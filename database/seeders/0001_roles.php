<?php

declare(strict_types=1);

return static function (PDO $connection): void {
    $insert = $connection->prepare(
        'INSERT INTO roles (slug, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE slug = roles.slug'
    );
    foreach ([
        'teacher' => 'Teacher',
        'school' => 'School',
        'parent' => 'Parent / Guardian',
        'admin' => 'Administrator',
    ] as $slug => $name) {
        $insert->execute([$slug, $name]);
    }
};
