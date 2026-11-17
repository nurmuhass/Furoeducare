<?php

declare(strict_types=1);

use Furoeducare\Modules\Vacancy\ApplicationStatus;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

try {
    $staff = [
        'submitted' => ['under_review'],
        'under_review' => ['shortlisted', 'unsuccessful'],
        'shortlisted' => ['interview_invited', 'unsuccessful'],
        'interview_invited' => ['offer_pending', 'unsuccessful'],
        'offer_pending' => ['successful', 'unsuccessful'],
        'successful' => [],
        'unsuccessful' => [],
        'withdrawn' => [],
    ];
    expect(count(ApplicationStatus::cases()) === count($staff), 'Eight distinct recruitment stages are available.');
    foreach ($staff as $stage => $actions) {
        $state = ApplicationStatus::from($stage);
        expect(array_column($state->transitions(true), 'value') === $actions, "Unexpected staff transitions from $stage.");
        expect(array_column($state->transitions(false), 'value') === ($actions === [] ? [] : ['withdrawn']),
            "Teachers can only withdraw an active application from $stage.");
    }
    expect(ApplicationStatus::fromStored('rejected') === ApplicationStatus::Unsuccessful, 'Prior rejections remain readable in history.');
    echo "PASS recruitment stages, all allowed transitions, terminal records and legacy history.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
