<?php

declare(strict_types=1);

/** Explicit integration-fixture assignment; never imported by application bootstrap. */
function grantTestStaffRole(PDO $db, int $id, string $slug = 'super_admin'): void
{
    $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if (preg_match('/\Afuroeducare_test_[a-z0-9_]+\z/D', $database) !== 1) {
        throw new RuntimeException('Staff fixtures require an isolated furoeducare_test_ schema.');
    }
    $query = $db->prepare('INSERT IGNORE INTO staff_user_roles (user_id, role_id)
        SELECT u.id, r.id FROM users u JOIN staff_roles r ON r.slug = ? WHERE u.id = ?
        AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles coarse ON coarse.id = ur.role_id WHERE ur.user_id = u.id AND coarse.slug = \'admin\')');
    $query->execute([$slug, $id]);
    $check = $db->prepare('SELECT COUNT(*) FROM staff_user_roles ur JOIN staff_roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?');
    $check->execute([$id, $slug]);
    if ((int) $check->fetchColumn() !== 1) {
        throw new RuntimeException('A fixture staff role could not be assigned.');
    }
}
