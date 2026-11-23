<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
use Furoeducare\Modules\Admin\ReportPeriod;
use Furoeducare\Core\HttpException;
$period = ReportPeriod::between('2020-01-02', '2020-01-02');
expect($period->bounds() === ['2020-01-01 23:00:00', '2020-01-02 23:00:00'], 'Inclusive WAT days produce half-open UTC bounds.');
expect($period->query() === ['from'=>'2020-01-02','to'=>'2020-01-02'], 'Date query round trip.');
expect(ReportPeriod::all()->bounds() === null && ReportPeriod::all()->query() === ['period'=>'all'], 'All-time period has no bounds.');
expect(ReportPeriod::between('9998-12-31','9998-12-31')->bounds()[1] === '9998-12-31 23:00:00', 'Maximum supported year is safe.');
foreach ([['','2020-01-01'],['2020-01-01',''],['2020-02-30','2020-03-01'],['2020-1-01','2020-01-01'],['2020-01-02','2020-01-01'],['0999-12-31','2020-01-01'],['9999-01-01','9999-01-02'],['2020-01-01 OR 1=1','2020-01-02']] as [$from,$to]) {
    try { ReportPeriod::between($from,$to); throw new RuntimeException('Invalid dates accepted.'); }
    catch (HttpException $e) { expect($e->status === 422, 'Invalid report dates return 422.'); }
}
echo "PASS report periods: strict dates, WAT boundaries, inclusive final day, all-time and year limit.\n";
