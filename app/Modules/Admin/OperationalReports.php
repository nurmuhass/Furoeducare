<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;
use Throwable;

/** Fixed aggregate projections. Every report freshly checks staff and module permissions. */
final class OperationalReports
{
    private const DATE_LABELS = [
        'teacher_registered' => 'Teacher registration', 'teacher_available' => 'Teacher registration cohort',
        'teacher_verified' => 'Teacher registration cohort', 'teacher_available_verified' => 'Teacher registration cohort',
        'school_approved' => 'School approval', 'parent_registered' => 'Parent registration',
        'vacancy_active' => 'Vacancy publication', 'application_submitted' => 'Application submission',
        'placement_successful' => 'Placement confirmation', 'tutoring_submitted' => 'Tutoring submission',
        'library_downloads' => 'Download recorded', 'training_requests' => 'Training request submitted',
        'message_threads' => 'Conversation created', 'messages_sent' => 'Message sent',
        'candidate_requests' => 'Candidate request created', 'cv_requests' => 'Information request created',
    ];
    public function __construct(private readonly Closure $database) {}

    public function snapshot(int $actor, ReportPeriod $period, bool $export = false): array
    {
        $db = ($this->database)();
        // Own the transaction: permissions remain share-locked until counts and optional audit commit.
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        try {
            StaffAccess::requireIn($db, $actor, 'reports.view', true);
            $permissions = StaffAccess::permissionsIn($db, $actor, true);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $today = $now->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d');
            $retentionCutoff = $now->modify('-365 days')->format('Y-m-d H:i:s.u');
            $definitions = self::definitions($today, $retentionCutoff);
            $parts = $parameters = $metrics = [];
            foreach ($definitions as [$key, $scope, $label, $source, $condition, $basis, $definition, $values]) {
                if (!in_array($scope, $permissions, true)) { continue; }
                $where = $condition;
                array_push($parameters, ...$values);
                if (($bounds = $period->bounds()) !== null) {
                    $where .= " AND {$basis} >= ? AND {$basis} < ?";
                    array_push($parameters, ...$bounds);
                }
                // All identifiers, predicates and aliases are application constants; only values are bound.
                $parts[] = "(SELECT COUNT(*) FROM {$source} WHERE {$where}) AS {$key}";
                $metrics[] = ['key' => $key, 'label' => $label, 'value' => 0, 'date_basis' => self::DATE_LABELS[$key], 'definition' => $definition];
            }
            // A single consistent InnoDB read view for every permitted source, with no record projection.
            if ($parts !== []) {
                $counts = StaffAccess::query($db, 'SELECT ' . implode(', ', $parts), $parameters)->fetch(PDO::FETCH_ASSOC);
                foreach ($metrics as &$metric) { $metric['value'] = (int) $counts[$metric['key']]; }
                unset($metric);
            }
            if ($export) {
                VerificationEvents::audit($db, $actor, 'reports.exported', ['from' => $period->from, 'to' => $period->to,
                    'metric_keys' => array_column($metrics, 'key')]);
            }
            $result = ['generated_at' => $now->format('Y-m-d\TH:i:s.u\Z'), 'as_of_date' => $today,
                'period' => ['from' => $period->from, 'to' => $period->to], 'metrics' => $metrics];
            $db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $error;
        }
    }

    /** Data scopes, SQL and definitions are fixed by the application, never selected by the request. */
    private static function definitions(string $today, string $retentionCutoff): array
    {
        $teacher = "teacher_profiles p JOIN users u ON u.id = p.user_id";
        $registered = "u.email_verified_at IS NOT NULL AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.slug='teacher')";
        $active = $registered . " AND u.suspended_at IS NULL AND p.verification_status <> 'suspended' AND p.teacher_suspension_at IS NULL";
        $date = "p.sections->>'$.availability.date'";
        // Validate the actual Gregorian date before accepting a from_date availability value.
        $validDate = "CASE WHEN {$date} REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN
            SUBSTRING({$date},1,4) BETWEEN '1000' AND '9998' AND SUBSTRING({$date},6,2) BETWEEN '01' AND '12'
            AND SUBSTRING({$date},9,2) BETWEEN '01' AND CASE
                WHEN SUBSTRING({$date},6,2) IN ('04','06','09','11') THEN '30'
                WHEN SUBSTRING({$date},6,2)='02' THEN IF(MOD(SUBSTRING({$date},1,4),400)=0 OR (MOD(SUBSTRING({$date},1,4),4)=0 AND MOD(SUBSTRING({$date},1,4),100)<>0),'29','28')
                ELSE '31' END ELSE 0 END";
        // Same recognized modes and empty-opportunity fallback as PublicDirectory::MODE_SQL.
        $modes = "(COALESCE(JSON_LENGTH(JSON_EXTRACT(p.sections,'$.availability.opportunities')),0)=0
            OR JSON_CONTAINS(p.sections,'\"full_time\"','$.availability.opportunities')
            OR JSON_CONTAINS(p.sections,'\"part_time\"','$.availability.opportunities')
            OR JSON_CONTAINS(p.sections,'\"private_tutoring\"','$.availability.opportunities')
            OR JSON_CONTAINS(p.sections,'\"online_tutoring\"','$.availability.opportunities'))";
        $available = "(p.sections->>'$.availability.status'='now' OR (p.sections->>'$.availability.status'='from_date' AND ({$validDate}) AND {$date} <= ?)) AND {$modes}";
        $cohort = 'Date filter: teacher profile creation cohort. Current state at report generation, not a historical point-in-time state.';
        $retained = "c.purged_at IS NULL AND (c.retention_hold=1 OR c.status <> 'closed' OR c.closed_at > ?)";
        $schoolRole = "EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.slug='school')";
        return [
            ['teacher_registered','teachers.view','Registered teachers',$teacher,$registered,'p.created_at','Registered teacher profiles with verified email, including suspended accounts and profiles. Date filter: profile creation.',[]],
            ['teacher_available','teachers.view','Available teachers',$teacher,"{$active} AND {$available}",'p.created_at','Active teachers with verified email, availability now or a valid start date on/before the WAT as-of date, and compatible teaching opportunities. '.$cohort,[$today]],
            ['teacher_verified','teachers.view','Verified teachers',$teacher,"{$active} AND p.verification_status='verified'",'p.created_at','Active teachers with verified email and verified professional profiles. '.$cohort,[]],
            ['teacher_available_verified','teachers.view','Available and verified teachers',$teacher,"{$active} AND p.verification_status='verified' AND {$available}",'p.created_at','Intersection of available and professionally verified active teachers. '.$cohort,[$today]],
            ['school_approved','schools.view','Approved schools','school_onboarding p JOIN users u ON u.id=p.user_id',"p.approved_at IS NOT NULL AND (p.review_status='approved' OR p.review_status IS NULL) AND p.school_suspension_at IS NULL AND u.suspended_at IS NULL AND u.email_verified_at IS NOT NULL AND {$schoolRole}",'p.approved_at','Currently approved schools (including legacy approval records) with the school account role and active, verified-email accounts. Date filter: school approval timestamp; current state, not past state.',[]],
            ['parent_registered','parents.view','Registered parents','parent_profiles p JOIN users u ON u.id=p.user_id',"u.email_verified_at IS NOT NULL AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.slug='parent')",'p.created_at','Registered parent profiles with verified email, including suspended accounts. Date filter: profile creation.',[]],
            ['vacancy_active','vacancies.view','Active vacancies','vacancies v JOIN school_onboarding s ON s.user_id=v.school_id JOIN users u ON u.id=s.user_id',"v.status='published' AND v.published_at IS NOT NULL AND v.application_deadline >= ?
                AND s.institution_name_key IS NOT NULL AND s.accepted_at IS NOT NULL
                AND COALESCE(s.review_status,IF(s.approved_at IS NULL,'pending','approved'))='approved'
                AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL AND {$schoolRole}",'v.published_at','Currently public published vacancies from complete, approved schools with active, verified-email school accounts and a deadline on/after the WAT as-of date, including today. Missing deadlines are excluded. Date filter: publication timestamp; current state, not past state.',[$today]],
            ['application_submitted','applications.view','Submitted applications','vacancy_applications a','1=1','a.created_at','All persisted vacancy applications, including withdrawn and other historical statuses; applications have no draft state. Date filter: application creation.',[]],
            ['placement_successful','placements.view','Successful placements','placements p',"p.confirmed_at IS NOT NULL AND p.status IN ('confirmed','closed')",'p.confirmed_at','Confirmed placements, including subsequently closed placements. Date filter: confirmation timestamp.',[]],
            ['tutoring_submitted','tutoring.view','Submitted tutoring requests','parent_tutoring_requests t',"t.submitted_at IS NOT NULL AND t.status <> 'draft'",'t.submitted_at','Submitted tutoring requests in any subsequent lifecycle state, excluding drafts. Date filter: submission timestamp.',[]],
            ['library_downloads','library.view','Library downloads','library_downloads d','1=1','d.created_at','Recorded download events, not unique people or resources. Date filter: download event creation.',[]],
            ['training_requests','training.view','Training requests','training_requests t','1=1','t.created_at','All persisted submitted training requests, including subsequent lifecycle states; this table has no drafts. Date filter: request creation.',[]],
            ['message_threads','messages.view','Message threads','message_conversations c',$retained,'c.created_at','Retained accessible conversations: not purged and held, not closed, or closed less than 365 days ago. Date filter: conversation creation.',[$retentionCutoff]],
            ['messages_sent','messages.view','Messages sent','conversation_messages m JOIN message_conversations c ON c.id=m.conversation_id',$retained,'m.created_at','Persisted messages in retained accessible conversations under the same retention rule. Date filter: message creation.',[$retentionCutoff]],
            ['candidate_requests','candidates.view','Candidate requests','candidate_requests c',"c.status <> 'draft'",'c.created_at','Submitted candidate requests in any subsequent lifecycle state, excluding drafts. Date filter: request creation.',[]],
            ['cv_requests','cv_requests.view','CV requests','candidate_disclosure_requests c','1=1','c.created_at','All persisted professional-information and original-CV disclosure requests, regardless of decision. Date filter: request creation.',[]],
        ];
    }
}
