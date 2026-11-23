<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Messaging;

use Furoeducare\Modules\Admin\StaffAccess;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\RateLimiter;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class MessageRepository
{
    private const VISIBLE = "c.purged_at IS NULL AND (c.retention_hold = 1 OR c.status <> 'closed'
        OR c.closed_at > UTC_TIMESTAMP(6) - INTERVAL 365 DAY)";

    public function __construct(
        private readonly Closure $database,
        private readonly MessageContext $contexts,
        private readonly RateLimiter $limits,
    ) {
    }

    public function create(
        int $actor, bool $staff, int $owner, Role $role, string $subject, string $body,
        string $context, ?int $contextId, string $token,
    ): int {
        $subject = MessageInput::subject($subject);
        $body = MessageInput::body($body);
        $token = MessageInput::token($token);
        if (!in_array($role, Role::publicRoles(), true)) {
            throw new DomainException('Choose a teacher, school or parent account.', 422);
        }
        $this->requireAccount($owner, $role);
        if ($staff) {
            $this->requireAccount($actor, Role::Admin);
            StaffAccess::requireIn(($this->database)(), $actor, 'messages.manage');
            if ($actor === $owner) {
                throw new DomainException('Use your account support page to contact management.', 403);
            }
        } elseif ($actor !== $owner) {
            throw self::missing();
        }
        $this->contexts->requireOwned($owner, $role, $context, $contextId);
        $existing = $this->rows('SELECT id FROM message_conversations WHERE owner_id = ? AND client_token = ?', [$owner, $token])[0] ?? null;
        if ($existing !== null) {
            return $this->existingCreation($actor, $staff, (int) $existing['id'], $subject, $body, $context, $contextId, $role);
        }
        $this->limit('messaging.create', $actor, 10, 3600);
        return Database::transaction(($this->database)(), function (PDO $db) use ($actor, $staff, $owner, $role, $subject, $body, $context, $contextId, $token): int {
            $this->requireAccount($owner, $role, lock: true);
            if ($staff) {
                $this->requireAccount($actor, Role::Admin, lock: true);
            }
            // The owner lock serialises concurrent creation retries across different browser sessions.
            $existing = $this->rows('SELECT id FROM message_conversations WHERE owner_id = ? AND client_token = ? FOR UPDATE', [$owner, $token])[0] ?? null;
            if ($existing !== null) {
                return $this->existingCreation($actor, $staff, (int) $existing['id'], $subject, $body, $context, $contextId, $role);
            }
            $this->contexts->requireOwned($owner, $role, $context, $contextId);
            $db->prepare('INSERT INTO message_conversations (owner_id, owner_role, subject, context_type, context_id, assigned_admin_id, client_token)
                VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$owner, $role->value, $subject, $context, $contextId, $staff ? $actor : null, $token]);
            $id = (int) $db->lastInsertId();
            $this->append($db, ['id' => $id, 'owner_id' => $owner, 'assigned_admin_id' => $staff ? $actor : null], $actor, $staff, $body, $token);
            VerificationEvents::audit($db, $actor, 'messaging.created', ['conversation_id' => $id, 'owner_id' => $owner]);
            return $id;
        });
    }

    private function existingCreation(int $actor, bool $staff, int $id, string $subject, string $body, string $context, ?int $contextId, Role $role): int
    {
        $thread = $this->thread($actor, $staff, $id);
        $first = $this->rows('SELECT body, sender_id FROM conversation_messages WHERE conversation_id = ? ORDER BY id LIMIT 1', [$id])[0] ?? null;
        if ($thread['subject'] !== $subject || $thread['context_type'] !== $context || $thread['context_id'] !== $contextId
            || $thread['owner_role'] !== $role->value || ($first['body'] ?? null) !== $body || (int) ($first['sender_id'] ?? 0) !== $actor) {
            throw new DomainException('This form was already used. Open a new conversation form.', 409);
        }
        return $id;
    }

    public function send(int $actor, bool $staff, int $id, string $body, string $token): void
    {
        if ($staff) {
            StaffAccess::requireIn(($this->database)(), $actor, 'messages.manage');
        }
        $body = MessageInput::body($body);
        $token = MessageInput::token($token);
        $thread = $this->thread($actor, $staff, $id);
        if ($this->sent($id, $actor, $token, $body)) {
            return;
        }
        $this->requireCanSend($thread, $actor, $staff);
        $this->limit('messaging.send', $actor, 30, 60);
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $staff, $id, $body, $token): void {
            $thread = $this->thread($actor, $staff, $id, lock: true);
            if (!$this->sent($id, $actor, $token, $body)) {
                $this->requireCanSend($thread, $actor, $staff);
                $this->append($db, $thread, $actor, $staff, $body, $token);
            }
        });
    }

    private function sent(int $id, int $actor, string $token, string $body): bool
    {
        $existing = $this->rows('SELECT body FROM conversation_messages WHERE conversation_id = ? AND sender_id = ? AND client_token = ?',
            [$id, $actor, $token])[0] ?? null;
        if ($existing !== null && $existing['body'] !== $body) {
            throw new DomainException('This form was already used for a different message. Reopen the conversation.', 409);
        }
        return $existing !== null;
    }

    private function requireCanSend(array $thread, int $actor, bool $staff): void
    {
        if ($thread['status'] === 'closed') {
            throw new DomainException('This conversation is closed. Contact management in a new support thread.', 409);
        }
        if ($staff && (int) $thread['assigned_admin_id'] !== $actor) {
            throw new DomainException('Assign this conversation to yourself before replying.', 403);
        }
    }

    private function append(PDO $db, array $thread, int $actor, bool $staff, string $body, string $token): void
    {
        $db->prepare('INSERT INTO conversation_messages (conversation_id, sender_id, is_staff, body, client_token) VALUES (?, ?, ?, ?, ?)')
            ->execute([$thread['id'], $actor, (int) $staff, $body, $token]);
        $message = (int) $db->lastInsertId();
        $db->prepare('UPDATE message_conversations SET last_message_id = ?, status = ?, revision = revision + 1 WHERE id = ?')
            ->execute([$message, $staff ? 'pending' : 'open', $thread['id']]);
        $context = ['conversation_id' => (int) $thread['id'], 'message_id' => $message];
        VerificationEvents::audit($db, $actor, 'messaging.sent', $context);
        if ($staff) {
            $recipients = [(int) $thread['owner_id']];
        } elseif ($thread['assigned_admin_id'] !== null
            && in_array('messages.manage', StaffAccess::permissionsIn($db, (int) $thread['assigned_admin_id'], true), true)) {
            $recipients = [(int) $thread['assigned_admin_id']];
        } else {
            $recipients = array_column($this->rows("SELECT u.id FROM users u WHERE u.suspended_at IS NULL AND u.email_verified_at IS NOT NULL
                AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'admin')
                AND EXISTS (SELECT 1 FROM staff_user_roles sr JOIN staff_role_permissions p ON p.role_id = sr.role_id WHERE sr.user_id = u.id AND p.permission = 'messages.manage')", []), 'id');
        }
        foreach ($recipients as $recipient) {
            if ((int) $recipient !== $actor) {
                VerificationEvents::record($db, $actor, (int) $recipient, 'messaging.new_message', $context,
                    'New Furoeducare message', 'Open Messages to read a new message.');
            }
        }
    }

    public function thread(int $actor, bool $staff, int $id, bool $lock = false): array
    {
        $row = $this->rows('SELECT c.* FROM message_conversations c WHERE c.id = ? AND ' . self::VISIBLE
            . ($staff ? '' : ' AND c.owner_id = ?') . ($lock ? ' FOR UPDATE' : ''), $staff ? [$id] : [$id, $actor])[0] ?? throw self::missing();
        $this->requireAccount($actor, $staff ? Role::Admin : Role::from($row['owner_role']), lock: $lock);
        $row['context_case_id'] = $row['context_type'] === 'candidate_information'
            ? ($this->rows('SELECT case_id FROM candidate_disclosure_requests WHERE id = ?', [$row['context_id']])[0]['case_id'] ?? null)
            : null;
        return $row;
    }

    public function messages(int $actor, bool $staff, int $id, int $before): array
    {
        $this->thread($actor, $staff, $id);
        return $this->rows('SELECT m.id, m.body, m.sender_id, m.is_staff, m.created_at,
            CASE WHEN m.is_staff = 1 THEN EXISTS (SELECT 1 FROM conversation_reads r JOIN message_conversations c ON c.id = r.conversation_id
                WHERE r.conversation_id = m.conversation_id AND r.user_id = c.owner_id AND r.last_read_message_id >= m.id)
            ELSE EXISTS (SELECT 1 FROM conversation_reads r JOIN message_conversations c ON c.id = r.conversation_id
                JOIN user_roles ur ON ur.user_id = r.user_id
                JOIN roles role ON role.id = ur.role_id AND role.slug = \'admin\'
                WHERE r.conversation_id = m.conversation_id AND r.user_id <> c.owner_id AND r.last_read_message_id >= m.id) END AS is_read
            FROM conversation_messages m WHERE m.conversation_id = ? AND m.id < ? ORDER BY m.id DESC LIMIT 31', [$id, $before]);
    }

    public function read(int $actor, bool $staff, int $id, int $last): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $staff, $id, $last): void {
            $this->thread($actor, $staff, $id, lock: true);
            if ($last < 1 || $this->rows('SELECT id FROM conversation_messages WHERE id = ? AND conversation_id = ?', [$last, $id]) === []) {
                throw new DomainException('Choose messages from this conversation to mark as read.', 422);
            }
            $db->prepare('INSERT INTO conversation_reads (conversation_id, user_id, last_read_message_id) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(conversation_reads.last_read_message_id, ?)')->execute([$id, $actor, $last, $last]);
        });
    }

    public function manage(int $staff, int $id, int $revision, string $action, string $value): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($staff, $id, $revision, $action, $value): void {
            $thread = $this->thread($staff, true, $id, lock: true);
            if ((int) $thread['revision'] !== $revision) {
                throw new DomainException('This conversation changed. Reopen it before recording your decision.', 409);
            }
            if ($action === 'assign') {
                $db->prepare('UPDATE message_conversations SET assigned_admin_id = ?, revision = revision + 1 WHERE id = ?')->execute([$staff, $id]);
                $context = ['previous_admin_id' => $thread['assigned_admin_id'], 'assigned_admin_id' => $staff];
            } else {
                if ((int) $thread['assigned_admin_id'] !== $staff) {
                    throw new DomainException('Assign this conversation to yourself before managing it.', 403);
                }
                if ($action === 'status' && in_array($value, ['open', 'pending', 'closed'], true)) {
                    if ($thread['status'] === $value || ($thread['status'] === 'closed' && $value !== 'open')) {
                        throw new DomainException('Choose an available conversation status.', 409);
                    }
                    $db->prepare("UPDATE message_conversations SET status = ?, closed_at = IF(? = 'closed', UTC_TIMESTAMP(6), NULL),
                        revision = revision + 1 WHERE id = ?")->execute([$value, $value, $id]);
                    $context = ['from' => $thread['status'], 'to' => $value];
                } elseif ($action === 'hold' && in_array($value, ['0', '1'], true)) {
                    $db->prepare('UPDATE message_conversations SET retention_hold = ?, revision = revision + 1 WHERE id = ?')->execute([(int) $value, $id]);
                    $context = ['hold' => $value === '1'];
                } else {
                    throw new DomainException('Choose a valid conversation action.', 422);
                }
            }
            VerificationEvents::audit($db, $staff, 'messaging.' . $action, ['conversation_id' => $id] + $context);
        });
    }

    public function threads(int $actor, bool $staff, int $before, array $filters): array
    {
        $params = [(int) !$staff, $actor, $before];
        $where = [self::VISIBLE, 'c.id < ?'];
        if ($staff) {
            $this->requireAccount($actor, Role::Admin);
        } else {
            $where[] = 'c.owner_id = ?';
            $params[] = $actor;
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles role ON role.id = ur.role_id
                WHERE ur.user_id = c.owner_id AND role.slug = c.owner_role)';
        }
        $status = $filters['status'] ?? 'all';
        $context = ($filters['context_type'] ?? '') ?: 'all';
        $assigned = $filters['assigned'] ?? 'all';
        if ($status !== 'all') {
            if (!in_array($status, ['open', 'pending', 'closed'], true)) {
                throw new DomainException('Choose a valid conversation status filter.', 422);
            }
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($staff && $context !== 'all') {
            if (!in_array($context, MessageInput::CONTEXTS, true)) {
                throw new DomainException('Choose a valid conversation category filter.', 422);
            }
            $where[] = 'c.context_type = ?';
            $params[] = $context;
        }
        if ($staff && $assigned === 'me') {
            $where[] = 'c.assigned_admin_id = ?';
            $params[] = $actor;
        } elseif ($staff && $assigned === 'unassigned') {
            $where[] = 'c.assigned_admin_id IS NULL';
        } elseif ($staff && $assigned !== 'all') {
            throw new DomainException('Choose a valid staff assignment filter.', 422);
        }
        $search = trim($filters['q'] ?? '');
        if ($staff && $search !== '') {
            if (preg_match('/\A.{1,160}\z/us', $search) !== 1) {
                throw new DomainException('Use up to 160 characters for conversation search.', 422);
            }
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where[] = "(c.subject LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM conversation_messages m
                WHERE m.conversation_id = c.id AND m.body LIKE ? ESCAPE '!'))";
            array_push($params, $term, $term);
        }
        return $this->rows('SELECT c.id, c.subject, c.status, c.owner_role, c.context_type, c.assigned_admin_id, c.updated_at,
            (SELECT COUNT(*) FROM conversation_messages m WHERE m.conversation_id = c.id
                AND m.id > COALESCE(r.last_read_message_id, 0) AND m.is_staff = ?) AS unread
            FROM message_conversations c LEFT JOIN conversation_reads r ON r.conversation_id = c.id AND r.user_id = ?
            WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT 21', $params);
    }

    public function unread(int $actor, bool $staff): int
    {
        if ($staff) {
            $this->requireAccount($actor, Role::Admin);
        }
        $params = [$actor, (int) !$staff];
        $owner = '';
        if (!$staff) {
            $owner = ' AND c.owner_id = ? AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles role ON role.id = ur.role_id
                WHERE ur.user_id = c.owner_id AND role.slug = c.owner_role)';
            $params[] = $actor;
        }
        return (int) $this->rows('SELECT COUNT(*) AS total FROM conversation_messages m JOIN message_conversations c ON c.id = m.conversation_id
            LEFT JOIN conversation_reads r ON r.conversation_id = c.id AND r.user_id = ? WHERE m.is_staff = ?
            AND m.id > COALESCE(r.last_read_message_id, 0) AND ' . self::VISIBLE . $owner, $params)[0]['total'];
    }

    public function purge(): int
    {
        return Database::transaction(($this->database)(), function (PDO $db): int {
            $rows = $this->rows("SELECT id FROM message_conversations WHERE status = 'closed' AND retention_hold = 0
                AND purged_at IS NULL AND closed_at <= UTC_TIMESTAMP(6) - INTERVAL 365 DAY ORDER BY id LIMIT 100 FOR UPDATE", []);
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $db->prepare('DELETE FROM conversation_messages WHERE conversation_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM conversation_reads WHERE conversation_id = ?')->execute([$id]);
                $db->prepare("UPDATE message_conversations SET subject = '[Expired conversation]', context_type = 'support', context_id = NULL,
                    last_message_id = NULL, purged_at = UTC_TIMESTAMP(6), revision = revision + 1 WHERE id = ?")->execute([$id]);
                $db->prepare("INSERT INTO audit_logs (action, context) VALUES ('messaging.purged', ?)")
                    ->execute([json_encode(['conversation_id' => $id], JSON_THROW_ON_ERROR)]);
            }
            return count($rows);
        });
    }

    private function requireAccount(int $id, Role $role, bool $lock = false): void
    {
        if ($role === Role::Admin) {
            StaffAccess::requireIn(($this->database)(), $id, $lock ? 'messages.manage' : 'messages.view', $lock);
        }
        if (!in_array($role, [Role::Teacher, Role::School, Role::Parent, Role::Admin], true)
            || $this->rows('SELECT u.id FROM users u WHERE u.id = ? AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
                AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles role ON role.id = ur.role_id WHERE ur.user_id = u.id AND role.slug = ?)'
                . ($lock ? ' FOR UPDATE' : ''), [$id, $role->value]) === []) {
            throw new DomainException('A verified active account with the appropriate role is required.', 403);
        }
    }

    private function limit(string $scope, int $actor, int $maximum, int $window): void
    {
        if (!$this->limits->allow($scope, (string) $actor, $maximum, $window)) {
            throw new DomainException('You have sent too many messages. Wait a moment before trying again.', 429);
        }
    }

    private function rows(string $sql, array $params): array
    {
        $query = ($this->database)()->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    }

    private static function missing(): DomainException
    {
        return new DomainException('This conversation could not be found.', 404);
    }
}
