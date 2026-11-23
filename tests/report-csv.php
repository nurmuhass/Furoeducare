<?php
declare(strict_types=1);
use Furoeducare\Modules\Admin\ReportCsv;
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
try {
    $snapshot = ['generated_at' => '2026-11-23T10:00:00Z', 'period' => ['from' => '2020-01-02', 'to' => '2020-01-02'],
        'metrics' => [['key' => 'sample', 'label' => " =HYPERLINK(\"unsafe\")", 'value' => 3, 'date_basis' => 'Created at', 'definition' => "Comma, quote \" and new\nline"]]];
    $csv = ReportCsv::encode($snapshot);
    expect(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV is UTF-8 with Excel-friendly BOM.');
    $stream = fopen('php://temp', 'w+'); fwrite($stream, substr($csv, 3)); rewind($stream);
    $header = fgetcsv($stream, escape: ''); $row = fgetcsv($stream, escape: '');
    expect(count($header) === 7 && count($row) === 7, 'CSV has seven stable aggregate columns.');
    expect(str_starts_with($row[0], "'") && $row[1] === '3' && $row[2] === '2020-01-02' && $row[3] === '2020-01-02', 'Formula-like cells are neutralised, counts and dates retained.');
    expect($row[5] === "Comma, quote \" and new\nline", 'CSV escaping round trips comma, quote and newline.');
    fclose($stream);
    $snapshot['period'] = ['from' => null, 'to' => null]; $snapshot['metrics'] = [];
    expect(substr_count(ReportCsv::encode($snapshot), "\r\n") === 1, 'Empty permission subset exports only headers.');
    echo "PASS aggregate CSV columns, UTF-8, formula defence, escaping and empty scope.\n";
} catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL); exit(1); }
