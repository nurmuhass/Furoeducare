<?php

declare(strict_types=1);

use Furoeducare\Modules\Parent\TutoringStatus;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$expected = [
    'draft' => [],
    'submitted' => ['under_review', 'closed'],
    'under_review' => ['matching', 'closed'],
    'matching' => ['shortlisted', 'closed'],
    'shortlisted' => ['arrangement_pending', 'closed'],
    'arrangement_pending' => ['confirmed', 'closed'],
    'confirmed' => [],
    'closed' => [],
];
try {
    expect(class_exists(TutoringStatus::class), 'Tutoring has a dedicated private workflow.');
    expect(count(TutoringStatus::cases()) === count($expected), 'Every request stage has its own state.');
    foreach ($expected as $state => $destinations) {
        expect(array_column(TutoringStatus::from($state)->staffTransitions(), 'value') === $destinations, "Invalid transitions for $state.");
    }
    echo "PASS tutoring stage progression, early closure and terminal states.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
