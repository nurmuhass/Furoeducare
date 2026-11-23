<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Messaging;

use Closure;
use DomainException;
use Furoeducare\Modules\Auth\Role;

final class MessageContext
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function requireOwned(int $owner, Role $role, string $type, ?int $id): void
    {
        if (!in_array($type, MessageInput::CONTEXTS, true) || ($type === 'support' ? $id !== null : ($id ?? 0) < 1)) {
            throw new DomainException('Choose a valid request or general support.', 422);
        }
        if ($type === 'support') {
            return;
        }
        if (($type === 'training' && $role !== Role::School)
            || ($type === 'recruitment' && $role !== Role::School)
            || ($type === 'vacancy' && !in_array($role, [Role::School, Role::Teacher], true))
            || ($type === 'tutoring' && !in_array($role, [Role::Parent, Role::Teacher], true))
            || ($type === 'candidate_information' && !in_array($role, [Role::School, Role::Parent], true))) {
            throw self::missing();
        }
        $sql = match ($type) {
            'training' => 'SELECT id FROM training_requests WHERE id = ? AND school_id = ?',
            'recruitment' => "SELECT id FROM candidate_requests WHERE id = ? AND requester_id = ?
                AND requester_role = 'school' AND category = 'recruitment'",
            'candidate_information' => 'SELECT d.id FROM candidate_disclosure_requests d JOIN candidate_requests c ON c.id = d.case_id
                WHERE d.id = ? AND c.requester_id = ? AND c.requester_role = ?',
            'vacancy' => $role === Role::Teacher
                ? 'SELECT id FROM vacancy_applications WHERE vacancy_id = ? AND teacher_id = ?'
                : 'SELECT id FROM vacancies WHERE id = ? AND school_id = ?',
            'tutoring' => $role === Role::Teacher
                ? "SELECT r.id FROM parent_tutoring_requests r WHERE r.id = ? AND
                    (r.confirmed_teacher_id = ? OR (r.status IN ('shortlisted','arrangement_pending') AND EXISTS (
                        SELECT 1 FROM tutoring_candidates c WHERE c.request_id = r.id AND c.teacher_id = ?)))"
                : 'SELECT id FROM parent_tutoring_requests WHERE id = ? AND parent_id = ?',
            'placement' => $role === Role::Teacher ? 'SELECT id FROM placements WHERE id = ? AND teacher_id = ?'
                : 'SELECT id FROM placements WHERE id = ? AND requester_id = ? AND kind = ? AND shortlisted_at IS NOT NULL',
        };
        $params = [$id, $owner];
        if ($type === 'candidate_information') {
            $params[] = $role->value;
        } elseif ($type === 'tutoring' && $role === Role::Teacher) {
            $params[] = $owner;
        } elseif ($type === 'placement' && $role !== Role::Teacher) {
            $params[] = $role === Role::School ? 'employment' : 'tutoring';
        }
        $query = ($this->database)()->prepare($sql);
        $query->execute($params);
        if ($query->fetch() === false) {
            throw self::missing();
        }
    }

    private static function missing(): DomainException
    {
        return new DomainException('This linked request could not be found for the account.', 404);
    }
}
