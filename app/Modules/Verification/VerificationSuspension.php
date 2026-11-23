<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

use PDO;

final class VerificationSuspension
{
    /**
     * Called while the shared user row is locked. Each review flow keeps its
     * own claim and can release it without clearing an independent suspension.
     */
    public static function update(
        PDO $db, int $id, bool $suspend, ?string $current, ?string $own, bool $fromTeacher,
    ): ?string {
        $other = $db->prepare($fromTeacher
            ? "SELECT school_suspension_at FROM school_onboarding WHERE user_id = ? AND review_status = 'suspended' FOR UPDATE"
            : "SELECT teacher_suspension_at FROM teacher_profiles WHERE user_id = ? AND verification_status = 'suspended' FOR UPDATE");
        $other->execute([$id]);
        $otherOwns = $current !== null && $other->fetchColumn() === $current;

        if ($suspend && $current === null) {
            \Furoeducare\Modules\Admin\StaffAccess::protectLastSuper($db, $id);
            $now = $db->query('SELECT CURRENT_TIMESTAMP(6)')->fetchColumn();
            $db->prepare('UPDATE users SET suspended_at = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$now, $id]);
            return $now;
        }
        if ($suspend) {
            return $otherOwns ? $current : null;
        }
        if ($own !== null && $own === $current && !$otherOwns) {
            $db->prepare('UPDATE users SET suspended_at = NULL, auth_version = auth_version + 1 WHERE id = ?')->execute([$id]);
        }
        return null;
    }
}
