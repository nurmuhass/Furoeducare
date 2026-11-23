<?php

declare(strict_types=1);

use Furoeducare\Core\HttpException;
use Furoeducare\Modules\Admin\AdminReadModel;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$model = new AdminReadModel(static fn () => throw new RuntimeException('Unexpected database access.'));
expect($model->overview([]) === ['metrics' => []], 'An unassigned staff member sees no metrics or audit activity.');
expect($model->search([], 'teacher', PHP_INT_MAX) === [], 'Search with no permissions performs no database reads.');
expect($model->search(['teachers.view'], '', PHP_INT_MAX) === [], 'An empty search does not enumerate accounts.');
expect($model->reports([], '2024-02-29', '2024-02-29') === [], 'Accept a real leap date and an inclusive single-day range.');

foreach ([
    static fn () => $model->accounts('administrator', [], PHP_INT_MAX),
    static fn () => $model->accounts('teacher', ['q' => []], PHP_INT_MAX),
    static fn () => $model->search([], "x\0y", PHP_INT_MAX),
    static fn () => $model->search([], str_repeat('a', 201), PHP_INT_MAX),
    static fn () => $model->reports([], '2026-02-29', '2026-03-01'),
    static fn () => $model->reports([], '2026-1-01', '2026-02-01'),
    static fn () => $model->reports([], "2026-01-01\0", '2026-02-01'),
    static fn () => $model->reports([], '2026-03-02', '2026-03-01'),
    static fn () => $model->audit(['actor' => '1 OR 1=1'], PHP_INT_MAX),
    static fn () => $model->audit(['actor' => '0'], PHP_INT_MAX),
] as $invalid) {
    try {
        $invalid();
        throw new RuntimeException('Invalid administration filter accepted.');
    } catch (HttpException $error) {
        expect($error->status === 422, 'Invalid filters return HTTP 422 before database access.');
    }
}
$pattern = new ReflectionMethod(AdminReadModel::class, 'pattern');
expect($pattern->invoke(null, '50%_!') === '%50!%!_!!%', 'LIKE wildcards and escape characters are escaped literally.');
$date = new ReflectionMethod(AdminReadModel::class, 'date');
$start = $date->invoke(null, '2026-11-23', new DateTimeZone('Africa/Lagos'));
expect($start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') === '2026-11-22 23:00:00', 'Report days start at WAT midnight expressed in UTC.');
expect($start->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') === '2026-11-23 23:00:00', 'Inclusive WAT end dates use the following exclusive midnight.');
echo "PASS administration read-model permissions, filters, literal search and WAT report dates.\n";
