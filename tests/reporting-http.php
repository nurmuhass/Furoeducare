<?php
declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/library-fixtures.php';

function reportStatus(array $response, int $expected, string $reason): void
{
    expect($response[0] === $expected, "$reason: expected $expected, got {$response[0]}. " . substr(strip_tags($response[2]), -180));
}

/** Parse the actual attachment as UTF-8 CSV, including its spreadsheet preamble. */
function reportCsv(array $response): array
{
    reportStatus($response, 200, 'Export succeeds');
    [$code, $headers, $body] = $response;
    expect(str_contains(strtolower($headers['content-type'] ?? ''), 'text/csv') && str_contains(strtolower($headers['content-type']), 'utf-8'), 'CSV declares UTF-8.');
    expect(preg_match('/\Aattachment;\s*filename="[a-z0-9-]+\.csv"\z/i', $headers['content-disposition'] ?? '') === 1, 'CSV has a fixed safe attachment filename.');
    expect(str_contains($headers['cache-control'] ?? '', 'no-store') && ($headers['x-content-type-options'] ?? '') === 'nosniff', 'CSV is private and cannot be MIME-sniffed.');
    expect(str_starts_with($body, "\xEF\xBB\xBF") && preg_match('//u', $body) === 1, 'CSV begins with a UTF-8 BOM and parses as UTF-8.');
    expect(!preg_match('/(?<!\r)\n/', $body), 'CSV uses CRLF records.');
    $stream = fopen('php://temp', 'w+'); fwrite($stream, substr($body, 3)); rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) $rows[] = $row;
    fclose($stream);
    expect(array_shift($rows) === ['Metric', 'Count', 'From (WAT)', 'To (WAT)', 'Date basis', 'Definition', 'Generated at (UTC)'], 'CSV columns describe only aggregate metrics and date semantics.');
    foreach ($rows as $row) {
        expect(count($row) === 7 && ctype_digit($row[1]), 'Each metric has a nonnegative integer count and seven columns.');
        foreach ($row as $cell) expect(preg_match('/\A[\s]*[=+@\-]/u', $cell) !== 1, 'No CSV cell can execute a spreadsheet formula.');
        expect($row[4] !== '' && $row[5] !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $row[6]) === 1, 'Every aggregate explains its date basis, definition and generation time.');
    }
    return $rows;
}

$root = dirname(__DIR__); $db = null; $accounts = []; $resourceIds = []; $customRole = null; $signupEmail = null;
$work = $root . '/storage/local-preview/reporting-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_(admin|settings)_[a-f0-9]{12}\z/', $config->databaseName) === 1,
        'Reporting HTTP tests require an existing isolated migrated loopback administration/settings schema.');
    $db = Database::connect($config);
    expect((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 86 && count(glob($root . '/database/migrations/*.php')) === 86, 'Free launch retains the existing 86-migration schema.');
    $tablesBefore = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tablesBefore as $table) expect(preg_match('/billing|payment|checkout|gateway|subscription|invoice/i', $table) !== 1, 'No billing or payment tables exist.');
    mkdir($work, 0700, true);
    $query = static function (string $sql, array $values = []) use ($db): PDOStatement { $q = $db->prepare($sql); $q->execute($values); return $q; };
    $password = 'Report synthetic fixture 934!'; $suffix = bin2hex(random_bytes(6));
    foreach (['super_admin', 'library_manager', 'training_coordinator', 'verification_officer', 'bare', 'custom', 'teacher'] as $name) {
        $email = 'http-report-' . $suffix . '-' . $name . '@example.test';
        $query('INSERT INTO users (email,password_hash,email_verified_at) VALUES (?,?,UTC_TIMESTAMP(6))', [$email,password_hash($password,PASSWORD_DEFAULT)]);
        $id = (int)$db->lastInsertId(); $accounts[$name] = compact('id','email');
        $query('INSERT INTO user_roles (user_id,role_id) SELECT ?,id FROM roles WHERE slug=?', [$id,$name === 'teacher' ? 'teacher' : 'admin']);
        if (!in_array($name, ['bare','custom','teacher'], true)) $query('INSERT INTO staff_user_roles(user_id,role_id) SELECT ?,id FROM staff_roles WHERE slug=?', [$id,$name]);
    }
    $query('INSERT INTO staff_roles(slug,name,built_in) VALUES (?,?,0)', ['http_report_'.$suffix,'Synthetic report scope']); $customRole = (int)$db->lastInsertId();
    foreach (['reports.view','library.view'] as $permission) $query('INSERT INTO staff_role_permissions(role_id,permission) VALUES (?,?)', [$customRole,$permission]);
    $query('INSERT INTO staff_user_roles(user_id,role_id) VALUES (?,?)', [$accounts['custom']['id'],$customRole]);
    putenv('MAIL_TRANSPORT=file'); putenv('MAIL_FROM=notifications@example.test');
    $signupEmail = 'free-signup-'.$suffix.'@example.test';
    serve($root, ['APP_ENV'=>'development','AUTH_SESSION_DIR'=>$work.'/sessions','AUTH_MAIL_DIR'=>$work.'/mail','APP_LOG_FILE'=>$work.'/app.log','LIBRARY_STORAGE_DIR'=>$work.'/library'],
        $work.'/server.log', static function (string $base) use ($root,$db,$query,$accounts,$password,$customRole,$work,$tablesBefore,$signupEmail,&$resourceIds): void {
        $path = '/dashboard/admin/reports'; $filter = '?from=2020-01-02&to=2020-01-02'; $export = $path.'/export';
        $guest = new AuthBrowser($base); $browsers = [];
        foreach ([$path,$export] as $route) redirectIs($guest->send($route), '/login');
        foreach ($accounts as $name => $account) {
            $browser = new AuthBrowser($base);
            redirectIs($browser->submit('/login',['email'=>$account['email'],'password'=>$password]), $name === 'teacher' ? '/dashboard/teacher' : '/dashboard/admin');
            $browsers[$name] = $browser;
            if (in_array($name,['teacher','bare','verification_officer'],true)) foreach ([$path,$export] as $route) foreach (['GET','HEAD'] as $method) reportStatus($browser->send($route,$method),403,'Direct report access is permission checked for '.$name);
        }
        echo "PASS guest redirects and direct GET/HEAD denials for teacher, bare Admin and Verification Officer.\n";
        $super = $browsers['super_admin'];
        $auditCount = static fn(int $id): int => (int)$query("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id=? AND action='reports.exported'",[$id])->fetchColumn();
        $before = $auditCount($accounts['super_admin']['id']);
        [$status,$headers,$html] = $super->send($path.$filter); reportStatus([$status,$headers,$html],200,'Filtered report page');
        expect(str_contains($html,'2020-01-02') && str_contains($html,'WAT') && str_contains($html,'Measure definitions'), 'Page displays selected WAT dates and metric explanations.');
        expect(preg_match('~href="'.preg_quote($export,'~').'\?[^" ]*from=2020-01-02(?:&amp;|&)to=2020-01-02"~',$html)===1, 'CSV link preserves both date filters.');
        file_put_contents($work.'/reports-raw.html',$html);
        file_put_contents($root.'/storage/local-preview/reporting-visual.html',preg_replace('~(["\'])/assets/~','$1file:///'.str_replace('\\','/',$root).'/public/assets/',$html));
        $head = $super->send($export.$filter,'HEAD'); reportStatus($head,200,'Authorized HEAD'); expect($head[2] === '' && $auditCount($accounts['super_admin']['id']) === $before,'HEAD returns no body and creates no export audit.');
        $response = $super->send($export.$filter); $all = reportCsv($response);
        expect(array_column($all,0) === ['Registered teachers','Available teachers','Verified teachers','Available and verified teachers','Approved schools','Registered parents','Active vacancies','Submitted applications','Successful placements','Submitted tutoring requests','Library downloads','Training requests','Message threads','Messages sent','Candidate requests','CV requests'],'Super Admin receives exactly the 16 application-owned aggregate labels.');
        foreach ($all as $row) expect($row[2] === '2020-01-02' && $row[3] === '2020-01-02','CSV records inclusive WAT filter dates.');
        foreach ($accounts as $account) expect(!str_contains($response[2],$account['email']),'CSV never exposes account rows or email addresses.');
        expect($auditCount($accounts['super_admin']['id']) === $before+1,'A real GET records exactly one successful export audit.');
        $audit = $query("SELECT context FROM audit_logs WHERE actor_user_id=? AND action='reports.exported' ORDER BY id DESC LIMIT 1",[$accounts['super_admin']['id']])->fetchColumn();
        expect(!str_contains($audit,'@example.test') && !str_contains($audit,'password'),'Audit contains aggregate export metadata only.');
        $library = reportCsv($browsers['library_manager']->send($export.$filter));
        expect(count($library) === 1 && stripos($library[0][0],'download') !== false,'Library Manager sees library downloads only.');
        $training = reportCsv($browsers['training_coordinator']->send($export.$filter));
        expect(array_column($training,0) === ['Approved schools','Training requests','Message threads','Messages sent'],'Training Coordinator sees school approval, training requests and message activity only.');
        echo "PASS filtered aggregate HTML/UTF-8 CSV, restricted dataset scopes, exact GET audit and read-only HEAD.\n";
        foreach (['from=2020-02-30&to=2020-03-01','from=2020-01-03&to=2020-01-02','from=2020-01-02','to=2020-01-02','from[]=2020-01-02&to=2020-01-02','from[]=2020-01-02&to[]=2020-01-02','period[]=all','from=%3Cscript%3Ealert(933)%3C%2Fscript%3E&to=2020-01-02'] as $bad) {
            foreach ([$path,$export] as $route) {
                $invalid = $super->send($route.'?'.$bad); reportStatus($invalid,422,'Invalid dates rejected');
                expect(!str_contains($invalid[1]['content-type'] ?? '', 'text/csv') && !isset($invalid[1]['content-disposition']) && !str_contains($invalid[2],'<script>alert(933)</script>'),'Invalid dates never emit a CSV or unsafe HTML.');
            }
        }
        expect($auditCount($accounts['super_admin']['id']) === $before+1,'Rejected exports create no success audit.');
        $default = reportCsv($super->send($export)); $now = new DateTimeImmutable('now',new DateTimeZone('Africa/Lagos'));
        expect($default[0][2] === $now->format('Y-m-01') && $default[0][3] === $now->format('Y-m-d'),'Missing filters default to the current WAT month through today.');
        reportStatus($super->send($path.'?period=all'),200,'Explicit all-time HTML'); reportCsv($super->send($export.'?period=all'));
        foreach ([$path,$export] as $route) {
            foreach (['POST','PUT','PATCH','DELETE','OPTIONS'] as $method) reportStatus($super->send($route,$method),405,'Unsupported report method '.$method);
            foreach (['PUT','PATCH','DELETE'] as $override) reportStatus($super->send($route,'POST',['_method'=>$override]),405,'Form overrides cannot bypass read-only reports');
        }
        $custom = $browsers['custom']; expect(count(reportCsv($custom->send($export.$filter)))===1,'Custom role initially sees its permitted library metric.');
        $query("DELETE FROM staff_role_permissions WHERE role_id=? AND permission='library.view'",[$customRole]);
        expect(reportCsv($custom->send($export.$filter)) === [],'Dataset permission revocation takes effect in an existing session.');
        $query("DELETE FROM staff_role_permissions WHERE role_id=? AND permission='reports.view'",[$customRole]);
        foreach ([$path,$export] as $route) foreach (['GET','HEAD'] as $method) reportStatus($custom->send($route.$filter,$method),403,'Report permission revocation takes effect in an existing session');
        echo "PASS strict dates, safe HTML, default/all-time filters, method denial and fresh permission revocation.\n";

        // Free launch is exercised through real successful registration and library delivery.
        $settingsBefore = $query('SELECT * FROM system_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC);
        $settings = '/dashboard/admin/settings'; $settingsHtml = $super->send($settings)[2];
        expect(preg_match('/name="revision" value="([0-9]+)"/',$settingsHtml,$revision)===1,'Settings expose revision.');
        reportStatus($super->submit($settings,['revision'=>$revision[1],'admin_notice'=>'','support_email'=>'','reason'=>'Synthetic forbidden payment switch','confirmed'=>'1','payments_enabled'=>'1']),422,'Payment switch injection refused');
        expect($settingsBefore === $query('SELECT * FROM system_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC),'Rejected injection changes no settings.');
        foreach (['/checkout','/payments','/payments/initialize','/payments/callback','/billing','/api/payments'] as $route) foreach (['GET','POST'] as $method) reportStatus($guest->send($route,$method),404,'Payment route is absent');
        redirectIs($guest->submit('/register',['email'=>$signupEmail]),'/login');
        expect((int)$query('SELECT COUNT(*) FROM users WHERE email=?',[$signupEmail])->fetchColumn()===1,'Ordinary registration creates an account without payment.');
        expect((int)$query("SELECT COUNT(*) FROM email_outbox WHERE recipient_email=? AND template='account_verification'",[$signupEmail])->fetchColumn()===1,'Free registration queues email verification.');
        $admin = $browsers['library_manager']; $libraryPath='/dashboard/admin/library';
        $title='=HYPERLINK("https://example.test") synthetic résumé'; $pdf=sampleCvPdf(['Free launch fixture','Download without checkout.']);
        $upload=libraryUpload($base,$admin,$libraryPath.'?new=1',libraryFields(['title'=>$title]),['material'=>['free.pdf','application/pdf',$pdf]]);
        reportStatus($upload,303,'Real library fixture upload');
        $resource=$query('SELECT * FROM library_resources WHERE created_by=? ORDER BY id DESC LIMIT 1',[$accounts['library_manager']['id']])->fetch(PDO::FETCH_ASSOC);
        expect(is_array($resource),'Own uploaded fixture exists.'); $id=(int)$resource['id']; $resourceIds[]=$id;
        $page=$libraryPath.'?id='.$id;
        $publish=static function()use($admin,$libraryPath,$query,$id,$page):void { reportStatus($admin->submit($libraryPath,['action'=>'publish','id'=>$id,'revision'=>$query('SELECT revision FROM library_resources WHERE id=?',[$id])->fetchColumn()],$page),303,'Publish synthetic resource'); };
        $publish(); $download='/digital-library/download?ref='.$resource['reference'];
        reportStatus($guest->send($download,'HEAD'),200,'Anonymous free PDF HEAD authorization');
        expect((int)$query('SELECT COUNT(*) FROM library_downloads WHERE resource_id=?',[$id])->fetchColumn()===0,'HEAD does not record a library download.');
        [$status,$headers,$bytes]=$guest->send($download);
        expect($status===200 && $bytes===$pdf && str_contains($headers['content-disposition']??'','attachment'),'Anonymous free library download delivers the actual authorized PDF.');
        expect((int)$query('SELECT COUNT(*) FROM library_downloads WHERE resource_id=? AND user_id IS NULL',[$id])->fetchColumn()===1,'The successful download is recorded as genuinely anonymous.');
        $aggregateWithUnsafeTitle=$super->send($export.'?period=all'); reportCsv($aggregateWithUnsafeTitle);
        expect(!str_contains($aggregateWithUnsafeTitle[2],$title) && !str_contains($aggregateWithUnsafeTitle[2],'HYPERLINK') && !str_contains($aggregateWithUnsafeTitle[2],'résumé'),'User-owned resource titles and formulas never enter aggregate CSV.');
        foreach (['/'.$resource['material_key'],'/storage/'.$resource['material_key']] as $guess) reportStatus($guest->send($guess),404,'Direct storage guesses stay inaccessible');
        $edit=static function(string $access)use($admin,$base,$libraryPath,$query,$id,$page,$title):void { reportStatus(libraryUpload($base,$admin,$page,libraryFields(['id'=>$id,'revision'=>$query('SELECT revision FROM library_resources WHERE id=?',[$id])->fetchColumn(),'title'=>$title,'access'=>$access])),303,'Change synthetic access metadata'); };
        $edit('future_paid'); $publish(); $future=$guest->send('/digital-library/resource?ref='.$resource['reference']); reportStatus($future,200,'Future-paid metadata remains listable');
        expect(!str_contains($future[2],'/checkout') && !str_contains($future[2],'/payments'),'Reserved future-paid metadata offers no checkout.');
        expect(in_array($guest->send($download)[0],[403,404],true),'Reserved future-paid material remains unavailable.');
        $edit('restricted'); $publish(); expect(in_array($guest->send($download)[0],[303,403,404],true),'Restricted material retains its existing authorization.');
        expect($tablesBefore===$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),'Free flows create no payment schema.');
        echo "PASS free registration and queued verification, anonymous original PDF, no checkout/payment routes or tables, rejected payment switch, reserved/restricted file controls.\n";
        echo 'Synthetic report preview: '.$root.'/storage/local-preview/reporting-visual.html'.PHP_EOL;
    });
} catch (Throwable $e) { fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL); $exit=1; }
finally {
    if ($db !== null) {
        foreach ($resourceIds as $id) {
            $db->prepare('DELETE FROM library_downloads WHERE resource_id=?')->execute([$id]);
            $db->prepare('DELETE FROM library_resources WHERE id=?')->execute([$id]);
        }
        foreach ($accounts as $account) {
            $db->prepare('DELETE FROM email_outbox WHERE user_id=?')->execute([$account['id']]);
            $db->prepare('DELETE FROM audit_logs WHERE actor_user_id=?')->execute([$account['id']]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$account['id']]);
        }
        if ($signupEmail!==null) {
            $db->prepare('DELETE FROM email_outbox WHERE recipient_email=?')->execute([$signupEmail]);
            $db->prepare('DELETE FROM users WHERE email=?')->execute([$signupEmail]);
        }
        if ($customRole!==null) { $db->prepare('DELETE FROM staff_role_permissions WHERE role_id=?')->execute([$customRole]); $db->prepare('DELETE FROM staff_roles WHERE id=?')->execute([$customRole]); }
    }
}
exit($exit);
