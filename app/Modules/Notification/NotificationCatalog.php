<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Notification;

use Furoeducare\Modules\Email\EmailTemplates;
use InvalidArgumentException;
use PDO;

/** Server-selected presentation and source identity for domain events. */
final class NotificationCatalog
{
    public static function describe(PDO $db, int $recipient, string $event, array $context): array
    {
        $template = match (true) {
            str_starts_with($event, 'verification.school.') => 'school_approval',
            str_starts_with($event, 'verification.teacher.'), $event === 'verification.document.review' => 'teacher_verification',
            str_starts_with($event, 'disclosure.') => 'cv_request_result',
            $event === 'application.interview_invited' && ($context['send_invitation'] ?? true) === true => 'interview_invitation',
            str_starts_with($event, 'candidate.'), str_starts_with($event, 'application.') => 'recruitment_update',
            str_starts_with($event, 'vacancy.') => 'vacancy_decision',
            $event === 'messaging.new_message' => 'new_internal_message',
            $event === 'placement.discussion_scheduled' => 'interview_invitation',
            str_starts_with($event, 'placement.') => 'placement_confirmation',
            str_starts_with($event, 'tutoring.') => 'tutoring_update',
            str_starts_with($event, 'training.') => 'training_confirmation',
            $event === 'account.verified' => 'welcome',
            $event === 'account.password_changed' => 'security_alert',
            default => throw new InvalidArgumentException('Unknown notification event.'),
        };
        $path = match ($template) {
            'school_approval' => '/dashboard/school',
            'teacher_verification' => '/dashboard/teacher',
            'cv_request_result' => '/candidate-requests',
            'recruitment_update' => str_starts_with($event, 'application.') ? '/dashboard/teacher' : '/candidate-requests',
            'vacancy_decision' => '/dashboard/school/vacancies?id=' . self::id($context, 'vacancy_id'),
            'tutoring_update' => '/dashboard/parent',
            'training_confirmation' => (($context['recipient_role'] ?? null) === 'admin'
                ? '/dashboard/admin/training/requests' : '/dashboard/school/training') . '?id=' . self::id($context, 'training_request_id'),
            'interview_invitation', 'placement_confirmation' => str_starts_with($event, 'application.')
                ? '/dashboard/teacher' : '/placements?id=' . self::id($context, 'placement_id'),
            'new_internal_message' => self::messagePath($db, $recipient, self::id($context, 'conversation_id')),
            'welcome', 'security_alert' => self::dashboard($db, $recipient),
        };
        $payload = ['path' => $path];
        if ($template !== 'training_confirmation' && is_string($context['to'] ?? null)) {
            $payload['decision'] = $context['to'];
        } elseif ($template === 'cv_request_result') {
            $payload['decision'] = match ($event) {
                'disclosure.approve' => 'approved', 'disclosure.reject' => 'rejected', 'disclosure.revoked' => 'revoked',
            };
        }
        return ['key' => self::key($db, $recipient, $event, $context), 'category' => EmailTemplates::category($template),
            'path' => $path, 'template' => $template, 'payload' => $payload];
    }

    private static function key(PDO $db, int $recipient, string $event, array $context): string
    {
        if (isset($context['event_key'])) {
            if (!is_string($context['event_key']) || $context['event_key'] === '' || strlen($context['event_key']) > 240) {
                throw new InvalidArgumentException('Invalid notification source key.');
            }
            return $context['event_key'];
        }
        if ($event === 'messaging.new_message') {
            return 'message:' . self::id($context, 'message_id');
        }
        foreach (['history_id', 'document_id', 'grant_id', 'disclosure_id'] as $source) {
            if (isset($context[$source])) {
                return $event . ':' . $source . ':' . self::id($context, $source);
            }
        }
        $source = match (true) {
            str_starts_with($event, 'training.') => 'training_request_id',
            str_starts_with($event, 'placement.') => 'placement_id',
            str_starts_with($event, 'tutoring.') => 'request_id',
            str_starts_with($event, 'candidate.') => 'case_id',
            str_starts_with($event, 'application.') => 'application_id',
            str_starts_with($event, 'vacancy.') => 'vacancy_id',
            default => null,
        };
        $id = $source === null ? $recipient : self::id($context, $source);
        $revision = $context['revision'] ?? null;
        if ($revision === null) {
            $sql = match (true) {
                str_starts_with($event, 'training.') => 'SELECT revision FROM training_requests WHERE id = ?',
                str_starts_with($event, 'placement.') => 'SELECT revision FROM placements WHERE id = ?',
                str_starts_with($event, 'verification.school.') => 'SELECT revision FROM school_onboarding WHERE user_id = ?',
                str_starts_with($event, 'verification.teacher.') => 'SELECT verification_revision FROM teacher_profiles WHERE user_id = ?',
                default => null,
            };
            if ($sql !== null) {
                $query = $db->prepare($sql);
                $query->execute([$id]);
                $revision = $query->fetchColumn();
            }
        }
        if (!is_numeric($revision) || (int) $revision < 1) {
            throw new InvalidArgumentException('A notification requires a stable source revision or history ID.');
        }
        return $event . ':' . $id . ':revision:' . (int) $revision;
    }

    private static function id(array $context, string $key): int
    {
        $value = $context[$key] ?? null;
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('Missing notification source ID: ' . $key);
        }
        return (int) $value;
    }

    private static function dashboard(PDO $db, int $recipient): string
    {
        $query = $db->prepare("SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id");
        $query->execute([$recipient]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $role) {
            if (in_array($role, ['admin', 'teacher', 'school', 'parent'], true)) {
                return '/dashboard/' . $role;
            }
        }
        return '/dashboard';
    }

    private static function messagePath(PDO $db, int $user, int $id): string
    {
        $query = $db->prepare('SELECT owner_id FROM message_conversations WHERE id = ?');
        $query->execute([$id]);
        return ((int) $query->fetchColumn() === $user ? '/messages' : '/dashboard/admin/messages') . '?id=' . $id;
    }
}
