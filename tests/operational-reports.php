<?php
declare(strict_types=1);
use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Admin\OperationalReports;
use Furoeducare\Modules\Admin\ReportPeriod;
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
expect(class_exists(OperationalReports::class), 'Operational reporting service is implemented.');
$config = Config::load(dirname(__DIR__));
expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
    && preg_match('/\Afuroeducare_test_(admin|settings)_[a-z0-9_]+\z/D', $config->databaseName) === 1,
    'Use a migrated isolated development loopback test database.');
$db = Database::connect($config);
$migrationCount = (int) $db->query("SELECT COUNT(*) FROM schema_migrations WHERE state='applied'")->fetchColumn();
expect($migrationCount === count(glob(dirname(__DIR__) . '/database/migrations/*.php')), 'All application migrations must be present.');
$history = array_column($db->query('SELECT version,checksum,state FROM schema_migrations')->fetchAll(), null, 'version');
foreach(glob(dirname(__DIR__) . '/database/migrations/*.php') as $path){
    $version=basename($path,'.php');
    expect(isset($history[$version]) && $history[$version]['state']==='applied'
        && hash('sha256',str_replace("\r\n","\n",file_get_contents($path)))===$history[$version]['checksum'],
        'Every isolated-schema migration must match the current application source.');
}
$service = new OperationalReports(static fn (): PDO => $db);
$period = ReportPeriod::between('2020-01-02','2020-01-02');
$suffix = bin2hex(random_bytes(6));
$fixtures = [];
$insert = static function (string $table, array $data, string $key = 'id') use ($db, &$fixtures): int {
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0,count($data),'?')) . ')';
    $db->prepare($sql)->execute(array_values($data));
    $id = $key === 'id' ? (int)$db->lastInsertId() : (int)$data[$key];
    $fixtures[] = [$table,$key,$id];
    return $id;
};
$user = static function (string $role, bool $verified = true, bool $suspended = false) use ($db,$insert,$suffix): int {
    static $index = 0;
    $id = $insert('users',['email'=>'report-'.$suffix.'-'.(++$index).'@example.test','password_hash'=>'!synthetic-no-login',
        'email_verified_at'=>$verified?'2020-01-01 00:00:00':null,'suspended_at'=>$suspended?'2020-01-01 00:00:00':null]);
    $db->prepare('INSERT INTO user_roles (user_id,role_id) SELECT ?,id FROM roles WHERE slug=?')->execute([$id,$role]);
    return $id;
};
$grant = static function(int $id,int $role) use($db):void { $db->prepare('INSERT INTO staff_user_roles(user_id,role_id) VALUES (?,?)')->execute([$id,$role]); };
$metrics = static fn(array $snapshot):array => array_column($snapshot['metrics'],'value','key');
$denied = static function(int $id)use($service,$period):void {
    try {$service->snapshot($id,$period); throw new RuntimeException('Unauthorized report accepted.');}
    catch(DomainException $e){expect($e->getCode()===403,'Report authorization is enforced freshly.');}
};
try {
    $staffRole=$insert('staff_roles',['slug'=>'report_'.$suffix,'name'=>'Report fixture','built_in'=>0]);
    foreach(['reports.view','teachers.view','schools.view','parents.view','vacancies.view','applications.view','placements.view','tutoring.view','library.view','training.view','messages.view','candidates.view','cv_requests.view'] as $permission){
        $db->prepare('INSERT INTO staff_role_permissions(role_id,permission) VALUES (?,?)')->execute([$staffRole,$permission]);
    }
    $actor=$user('admin'); $grant($actor,$staffRole);
    $baseline=$metrics($service->snapshot($actor,$period));
    $allBaseline=$metrics($service->snapshot($actor,ReportPeriod::all()));
    expect(count($baseline)===16,'All authorized metric keys are returned.');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
    $teacher = static function(string $created,bool $email=true,bool $suspended=false,string $status='verified',array $availability=['status'=>'now'],string $role='teacher')use($user,$insert):int {
        $id=$user($role,$email,$suspended);
        $insert('teacher_profiles',['user_id'=>$id,'category'=>'teacher','first_name'=>'Synthetic','last_name'=>'Report','phone'=>'+2348000000000','terms_version'=>'test','privacy_version'=>'test',
            'accepted_at'=>'2020-01-01 00:00:00','published_at'=>'2020-01-01 00:00:00','verification_status'=>$status,'sections'=>json_encode(['availability'=>$availability]),'created_at'=>$created],'user_id');
        return $id;
    };
    $t=$teacher('2020-01-01 23:00:00');
    $teacher('2020-01-02 22:59:59.999999',true,true);
    $teacher('2020-01-02 12:00:00',false);
    $teacher('2020-01-02 12:00:00',true,false,'incomplete',['status'=>'from_date','date'=>$today]);
    $teacher('2020-01-02 12:00:00',true,false,'verified',['status'=>'from_date','date'=>'2020-02-30']);
    $teacher('2020-01-02 12:00:00',true,false,'verified',['status'=>'now','opportunities'=>['invalid']]);
    $teacher('2020-01-02 12:00:00',true,false,'suspended');
    $teacher('2020-01-02 12:00:00',true,false,'verified',['status'=>'now'],'parent');
    $teacher('2020-01-01 22:59:59.999999');
    $teacher('2020-01-02 23:00:00');
    $parent=$user('parent',true,true);
    $insert('parent_profiles',['user_id'=>$parent,'first_name'=>'Synthetic','last_name'=>'Report','phone'=>'+2348000000000','state_code'=>'LA','city'=>'Lagos','terms_version'=>'test','privacy_version'=>'test','accepted_at'=>'2020-01-02 00:00:00','created_at'=>'2020-01-02 00:00:00'],'user_id');
    $school=$user('school');
    $insert('school_onboarding',['user_id'=>$school,'school_name'=>'Synthetic report fixture','institution_name_key'=>'report_'.$suffix,'accepted_at'=>'2020-01-01 00:00:00','review_status'=>null,'approved_at'=>'2020-01-02 00:00:00'],'user_id');
    $vacancy=$insert('vacancies',['school_id'=>$school,'status'=>'published','content'=>'{}','application_deadline'=>$today,'published_at'=>'2020-01-02 00:00:00']);
    $insert('vacancies',['school_id'=>$school,'status'=>'published','content'=>'{}','application_deadline'=>'2020-01-01','published_at'=>'2020-01-02 00:00:00']);
    $insert('vacancies',['school_id'=>$school,'status'=>'draft','content'=>'{}','published_at'=>'2020-01-02 00:00:00']);
    $insert('vacancies',['school_id'=>$school,'status'=>'published','content'=>'{}','application_deadline'=>null,'published_at'=>'2020-01-02 00:00:00']);
    foreach([['parent',true,false,'approved',true],['school',false,false,'approved',true],['school',true,true,'approved',true],['school',true,false,'pending',true],['school',true,false,'approved',false]] as [$role,$email,$suspended,$review,$complete]){
        $unavailableSchool=$user($role,$email,$suspended);
        $insert('school_onboarding',['user_id'=>$unavailableSchool,'school_name'=>'Synthetic unavailable school','institution_name_key'=>$complete?'report_'.bin2hex(random_bytes(6)):null,'accepted_at'=>$complete?'2020-01-01 00:00:00':null,'review_status'=>$review,
            'approved_at'=>$complete?'2020-01-02 00:00:00':null],'user_id');
        $insert('vacancies',['school_id'=>$unavailableSchool,'status'=>'published','content'=>'{}','application_deadline'=>$today,'published_at'=>'2020-01-02 00:00:00']);
    }
    $application=$insert('vacancy_applications',['vacancy_id'=>$vacancy,'teacher_id'=>$t,'status'=>'withdrawn','candidate_reference'=>'CA-'.bin2hex(random_bytes(16)),'vacancy_revision'=>1,'job_title'=>'Synthetic','job_snapshot'=>'{}','created_at'=>'2020-01-02 00:00:00']);
    $insert('placements',['kind'=>'employment','application_id'=>$application,'requester_id'=>$school,'teacher_id'=>$t,'teacher_reference'=>'synthetic','assigned_admin_id'=>$actor,'status'=>'closed','confirmed_at'=>'2020-01-02 00:00:00']);
    $insert('parent_tutoring_requests',['parent_id'=>$parent,'subject'=>'Synthetic','requirements'=>'Synthetic','status'=>'submitted','submitted_at'=>'2020-01-02 00:00:00']);
    $insert('parent_tutoring_requests',['parent_id'=>$parent,'subject'=>'Synthetic','requirements'=>'Synthetic','status'=>'draft','created_at'=>'2020-01-02 00:00:00']);
    foreach([['open',0,null,null],['closed',1,'2020-01-01 00:00:00',null],['closed',0,'2020-01-01 00:00:00',null],['open',0,null,'2020-01-01 00:00:00']] as [$status,$hold,$closed,$purged]) {
        $thread=$insert('message_conversations',['owner_id'=>$t,'owner_role'=>'teacher','subject'=>'Synthetic report','context_type'=>'support','status'=>$status,'retention_hold'=>$hold,'closed_at'=>$closed,'purged_at'=>$purged,'client_token'=>bin2hex(random_bytes(16)),'created_at'=>'2020-01-02 00:00:00']);
        $insert('conversation_messages',['conversation_id'=>$thread,'sender_id'=>$t,'is_staff'=>0,'body'=>'Synthetic fixture','client_token'=>bin2hex(random_bytes(16)),'created_at'=>'2020-01-02 00:00:00']);
    }
    $case=$insert('candidate_requests',['requester_id'=>$school,'requester_role'=>'school','category'=>'recruitment','purpose'=>'Synthetic','requirements'=>'Synthetic','status'=>'submitted','created_at'=>'2020-01-02 00:00:00']);
    $insert('candidate_requests',['requester_id'=>$school,'requester_role'=>'school','category'=>'recruitment','purpose'=>'Synthetic','requirements'=>'Synthetic','status'=>'draft','created_at'=>'2020-01-02 00:00:00']);
    $insert('candidate_disclosure_requests',['case_id'=>$case,'teacher_id'=>$t,'reference'=>'synthetic','scope'=>'professional','created_at'=>'2020-01-02 00:00:00']);
    $resource=$insert('library_resources',['reference'=>'LR-'.bin2hex(random_bytes(16)),'title'=>'Synthetic report','description'=>'Synthetic report fixture']);
    $insert('library_downloads',['resource_id'=>$resource,'resource_revision'=>1,'user_id'=>$t,'title_snapshot'=>'Synthetic report','created_at'=>'2020-01-02 00:00:00']);
    $insert('training_requests',['reference'=>'TR-'.bin2hex(random_bytes(16)),'school_id'=>$school,'participants'=>1,'location'=>'Synthetic','preferred_dates'=>'[]','requirements'=>'Synthetic','client_token'=>bin2hex(random_bytes(16)),'payload_hash'=>str_repeat('0',64),'status'=>'cancelled','created_at'=>'2020-01-02 00:00:00']);
    $snapshot=$service->snapshot($actor,$period); $after=$metrics($snapshot);
    foreach(['teacher_registered'=>6,'teacher_available'=>2,'teacher_verified'=>3,'teacher_available_verified'=>1,'parent_registered'=>1,'school_approved'=>1,'vacancy_active'=>1,'application_submitted'=>1,'placement_successful'=>1,'tutoring_submitted'=>1,'message_threads'=>2,'messages_sent'=>2,'candidate_requests'=>1,'cv_requests'=>1,'library_downloads'=>1,'training_requests'=>1] as $key=>$delta){
        expect($after[$key]-$baseline[$key]===$delta,$key.' uses documented visibility and WAT period basis.');
    }
    expect($snapshot['as_of_date']===$today && $snapshot['period']===['from'=>'2020-01-02','to'=>'2020-01-02'],'Snapshot captures WAT date and requested period.');
    foreach($snapshot['metrics'] as $metric){expect(array_keys($metric)===['key','label','value','date_basis','definition'] && is_int($metric['value']),'Only aggregate metric projections are exposed.');}
    $all=$service->snapshot($actor,ReportPeriod::all());
    expect($all['period']===['from'=>null,'to'=>null],'All-time metadata has no artificial date restriction.');
    foreach(['teacher_registered'=>8,'teacher_available'=>4,'teacher_verified'=>5,'teacher_available_verified'=>3] as $key=>$delta){
        expect($metrics($all)[$key]-$allBaseline[$key]===$delta,'All-time '.$key.' includes records outside the selected day.');
    }
    $auditCount = static function() use($db,$actor):int {
        $query=$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id=? AND action='reports.exported'");$query->execute([$actor]);return (int)$query->fetchColumn();
    };
    expect($auditCount()===0,'Normal snapshots do not create export audit records.');
    $export=$service->snapshot($actor,$period,true);
    expect($auditCount()===1,'An export records exactly one audit event in the report transaction.');
    $query=$db->prepare("SELECT context FROM audit_logs WHERE actor_user_id=? AND action='reports.exported'");$query->execute([$actor]);
    $audit=json_decode($query->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    expect(array_keys($audit)===['to','from','metric_keys'] || array_keys($audit)===['from','to','metric_keys'],'Export audit projects only dates and metric keys.');
    expect($audit['from']==='2020-01-02' && $audit['to']==='2020-01-02' && $audit['metric_keys']===array_column($export['metrics'],'key'),'Export audit describes the permission-scoped snapshot.');
    $db->prepare('DELETE FROM staff_role_permissions WHERE role_id=? AND permission=?')->execute([$staffRole,'teachers.view']);
    expect(!isset($metrics($service->snapshot($actor,$period))['teacher_registered']),'Revoked module scopes omit metrics rather than emit zero.');
    $db->prepare('DELETE FROM staff_role_permissions WHERE role_id=? AND permission=?')->execute([$staffRole,'reports.view']);
    $denied($actor);
    $db->prepare('INSERT INTO staff_role_permissions(role_id,permission) VALUES (?,?)')->execute([$staffRole,'reports.view']);
    foreach([['parent',true,false],['admin',false,false],['admin',true,true]] as [$role,$email,$suspended]){$bad=$user($role,$email,$suspended);$grant($bad,$staffRole);$denied($bad);}
    echo "PASS operational reports: aggregate-only snapshot, WAT cohort bounds, current states, lifecycle history, draft and retention exclusions, fresh authorization and scoped omission.\n";
} finally {
    if($db->inTransaction()){$db->rollBack();}
    foreach(array_reverse($fixtures) as [$table,$key,$id]){
        if($table==='users'){$db->prepare('DELETE FROM audit_logs WHERE actor_user_id=?')->execute([$id]);$db->prepare('DELETE FROM staff_user_roles WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$id]);}
        if($table==='staff_roles'){$db->prepare('DELETE FROM staff_role_permissions WHERE role_id=?')->execute([$id]);}
        $db->prepare('DELETE FROM '.$table.' WHERE '.$key.'=?')->execute([$id]);
    }
}
