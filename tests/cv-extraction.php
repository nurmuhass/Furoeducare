<?php

declare(strict_types=1);

use Furoeducare\Modules\Teacher\Cv\CvDraft;
use Furoeducare\Modules\Teacher\Cv\CvParser;
use Furoeducare\Modules\Teacher\Cv\ExtractionFailure;
use Furoeducare\Modules\Teacher\Cv\IsolatedCvExtractor;
use Furoeducare\Modules\Teacher\Cv\RuleBasedExtractor;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

require __DIR__ . '/support/cv-fixtures.php';

$work = sys_get_temp_dir() . '/furo-cv-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$exit = 0;
try {
    $lines = [
        'Skills: Lesson planning; Assessment',
        'Teaching Experience',
        'Teacher at Abeni School | 2020 - 2023',
        'Led chemistry lessons.',
        'Tutor at Adede Learning Centre | 2023 - Present',
        'Academic Qualifications',
        'B.Ed. | Fixture University | 2019',
        'Subjects Taught: Chemistry, Basic Science',
        'Certifications',
        'Child safeguarding',
        'First aid',
        'Professional Summary',
        'Science teacher with experience in classroom and tutoring roles.',
        'References',
        'Referee information',
    ];
    $parser = new CvParser();
    $draft = CvDraft::validate($parser->parse(implode("\n", $lines)));
    expect(count($draft['experience']) === 2 && $draft['experience'][1]['organisation'] === 'Adede Learning Centre'
        && $draft['experience'][1]['ended_year'] === null, 'Keep multiple jobs without inventing an end year.');
    expect(count($draft['certifications']) === 2 && $draft['qualifications'][0]['completed_year'] === 2019, 'Retain structured qualifications and certifications.');
    expect($draft['summary'] === $lines[12] && !str_contains(json_encode($draft), 'Referee'), 'Respect headings in unusual ordering and ignore references.');
    $missing = CvDraft::validate($parser->parse("Education\nB.Sc.\n\nSkills\nTeaching"));
    expect($missing['summary'] === null && $missing['experience'] === []
        && $missing['qualifications'][0]['institution'] === null, 'Missing values must remain blank.');

    $orgFirst = CvDraft::validate($parser->parse("Experience\nFirst Academy\nTeacher\n2019 - 2021\nSecond Academy\nTutor\n2021 - Present"));
    expect(array_column($orgFirst['experience'], 'organisation') === ['First Academy', 'Second Academy'],
        'Preserve employers when adjacent entries list the organisation first.');

    $invalid = $draft;
    $invalid['experience'][0]['started_year'] = '2020';
    try {
        CvDraft::validate($invalid);
        expect(false, 'Draft schema must reject an invalid field type.');
    } catch (InvalidArgumentException) {
        // Expected.
    }

    $extractor = new RuleBasedExtractor($parser);
    file_put_contents($work . '/teacher.pdf', sampleCvPdf($lines));
    sampleCvDocx($work . '/teacher.docx', ["Certifications", "Teaching certificate", "Work History", "Role: Teacher",
        "Employer: Àjọ Academy", "2017 – 2019", "", "Role: Tutor", "Employer: Second Academy", "2021 – Present",
        "Education", "B.Ed. | Fixture University | 2016", "PGDE | Fixture College | 2020"]);
    $pdf = $extractor->extract($work . '/teacher.pdf', 'application/pdf');
    expect($pdf['subjects'] === ['Chemistry', 'Basic Science'] && count($pdf['experience']) === 2, 'Read text and sections from a real PDF.');
    $docx = $extractor->extract($work . '/teacher.docx', RuleBasedExtractor::DOCX);
    expect($docx['experience'][0]['organisation'] === 'Àjọ Academy' && count($docx['experience']) === 2
        && count($docx['qualifications']) === 2 && $docx['summary'] === null, 'Read DOCX text, Unicode and multiple repeatable entries.');

    $isolated = new IsolatedCvExtractor(dirname(__DIR__) . '/bin/cv-parse.php');
    expect($isolated->extract($work . '/teacher.docx', RuleBasedExtractor::DOCX) === $docx, 'Read DOCX in the isolated worker subprocess.');

    file_put_contents($work . '/scan.pdf', sampleCvPdf([], true));
    try {
        $extractor->extract($work . '/scan.pdf', 'application/pdf');
        expect(false, 'An image-only PDF must not become an empty success.');
    } catch (ExtractionFailure $failure) {
        expect($failure->reason === 'scanned_pdf', 'Identify scanned PDFs for the manual fallback.');
    }
    echo "PASS CV schema, missing data, section ordering, multiple employers, PDF and DOCX, and scanned PDF detection.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    $exit = 1;
} finally {
    foreach (glob($work . '/*') as $file) {
        unlink($file);
    }
    rmdir($work);
}
exit($exit);
