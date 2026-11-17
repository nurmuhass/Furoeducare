<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Teacher\Cv\CvJobs;
use Furoeducare\Modules\Teacher\Cv\ExtractionFailure;
use Furoeducare\Modules\Teacher\Cv\IsolatedCvExtractor;
use Furoeducare\Modules\Teacher\TeacherDocuments;
use Furoeducare\Modules\Teacher\TeacherRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if ($argc > 2 || ($argc === 2 && $argv[1] !== '--once')) {
    fwrite(STDERR, "Usage: php bin/cv-worker.php [--once]\n");
    exit(2);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('memory_limit', '128M');
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $config = Config::load($root);
    $pdo = Database::connect($config);
    $database = static fn (): PDO => $pdo;
    $jobs = new CvJobs($database);
    $documents = new TeacherDocuments(new TeacherRepository($database), $config->teacherDocumentDirectory, $root . '/public');
    $extractor = new IsolatedCvExtractor(__DIR__ . '/cv-parse.php');
    $jobs->expire();
    $processed = 0;
    $limit = $argc === 2 ? 1 : 10;
    for (; $processed < $limit && ($job = $jobs->claim()) !== null; $processed++) {
        $id = (int) $job['document_id'];
        try {
            $draft = $extractor->extract($documents->privatePath($job['storage_key']), $job['media_type']);
        } catch (ExtractionFailure $failure) {
            $jobs->fail($id, $failure->reason);
            continue;
        } catch (Throwable) {
            $jobs->fail($id, 'unreadable');
            continue;
        }
        $jobs->ready($id, $draft);
    }
    fwrite(STDOUT, "CV extraction jobs processed: $processed\n");
} catch (Throwable) {
    fwrite(STDERR, "CV worker could not run. Check PHP dependencies, application configuration, and database migrations.\n");
    exit(1);
}
