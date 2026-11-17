<?php

declare(strict_types=1);

use Furoeducare\Modules\Teacher\Cv\CvParser;
use Furoeducare\Modules\Teacher\Cv\ExtractionFailure;
use Furoeducare\Modules\Teacher\Cv\RuleBasedExtractor;

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    exit(2);
}
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $payload = ['draft' => (new RuleBasedExtractor(new CvParser()))->extract($argv[1], $argv[2])];
} catch (ExtractionFailure $failure) {
    $payload = ['failure' => $failure->reason];
} catch (Throwable) {
    $payload = ['failure' => 'unreadable'];
}

echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
