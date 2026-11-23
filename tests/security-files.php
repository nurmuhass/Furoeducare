<?php
declare(strict_types=1);

use Furoeducare\Modules\Teacher\Cv\CvContent;
use Furoeducare\Modules\Teacher\Cv\ExtractionFailure;
use Furoeducare\Modules\Teacher\Cv\RuleBasedExtractor;
use Furoeducare\Modules\Teacher\Cv\CvParser;
use Furoeducare\Modules\Teacher\TeacherDocuments;
use Furoeducare\Modules\Teacher\TeacherRepository;
use Furoeducare\Modules\Library\LibraryFiles;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/cv-fixtures.php';

$work = sys_get_temp_dir() . '/furo-security-files-' . bin2hex(random_bytes(8));
mkdir($work, 0700);
mkdir($work . '/public', 0700);
$failures = [];
$checks = 0;
$check = static function (bool $ok, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) { $failures[] = $message; }
};
$rejects = static function (callable $action): bool {
    try { $action(); return false; } catch (ExtractionFailure | RuntimeException | InvalidArgumentException) { return true; }
};
try {
    $files = new LibraryFiles($work . '/library', $work . '/public');
    foreach (['/JavaScript', '/Java#53cript', '/JS', '/Launch', '/OpenAction', '/AA', '/EmbeddedFiles', '/EmbeddedFile', '/EF', '/E#46', '/FileAttachment', '/RichMedia', '/XFA', '/SubmitForm', '/ImportData', '/GoToR', '/Encrypt', '/ObjStm'] as $token) {
        $pdf = sampleCvPdf(['Skills: Teaching'], catalogEntries: $token . ' null');
        file_put_contents($work . '/active.pdf', $pdf);
        $check(!CvContent::isPdf($work . '/active.pdf', strlen($pdf)), 'Reject PDF active/opaque name ' . $token);
        $check($rejects(fn() => $files->validateMaterial($work . '/active.pdf', 'pdf')), 'Library rejects PDF name ' . $token);
    }
    $pdf = sampleCvPdf(['Skills: Teaching'], catalogEntries: '/OpenAction << /S /Java#53cript /JS (void\(0\)) >>');
    file_put_contents($work . '/action.pdf', $pdf);
    $check(!CvContent::isPdf($work . '/action.pdf', strlen($pdf)), 'Reject actual JavaScript catalog action with valid xref offsets.');
    $extractor = new RuleBasedExtractor(new CvParser());
    $check($rejects(fn() => $extractor->extract($work . '/action.pdf', 'application/pdf')), 'Worker revalidates PDF before parser.');
    // Embedded-file streams may omit their optional /Type /EmbeddedFile entry.
    // Use a real annotation and cross-reference table, with harmless text payload.
    $pageContent = 'BT /F1 11 Tf 45 760 Td (Teaching) Tj ET';
    $attachmentContent = "harmless\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R /Annots [7 0 R] >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($pageContent) . " >>\nstream\n$pageContent\nendstream",
        '<< /Length ' . strlen($attachmentContent) . " >>\nstream\n$attachmentContent\nendstream",
        '<< /Type /Annot /Subtype /FileAttachment /Rect [20 20 40 40] /FS 8 0 R >>',
        '<< /Type /Filespec /F (harmless.txt) /EF << /F 6 0 R >> >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 9\n0000000000 65535 f \n";
    foreach ($offsets as $offset) { $pdf .= sprintf("%010d 00000 n \n", $offset); }
    $pdf .= "trailer\n<< /Size 9 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($work . '/attachment.pdf', $pdf);
    $check(!CvContent::isPdf($work . '/attachment.pdf', strlen($pdf)), 'Reject embedded attachment with untyped stream.');
    $check($rejects(fn() => $files->validateMaterial($work . '/attachment.pdf', 'pdf')), 'Library rejects untyped embedded attachment.');
    file_put_contents($work . '/valid.pdf', sampleCvPdf(['Skills: Teaching']));
    $check(CvContent::isPdf($work . '/valid.pdf', filesize($work . '/valid.pdf')), 'Accept simple PDF.');
    foreach (['external-template', 'embedded', 'traversal', 'macro', 'bomb', 'xxe', 'symlink'] as $case) {
        $path = $work . '/' . $case . '.docx';
        sampleCvDocx($path, ['Skills: Teaching']);
        $zip = new ZipArchive();
        $zip->open($path);
        if ($case === 'external-template') {
            $zip->addFromString('word/_rels/settings.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" TargetMode="External" Target="https://example.invalid/template.dotm"/></Relationships>');
        } elseif ($case === 'embedded') { $zip->addFromString('word/embeddings/object.bin', 'synthetic');
        } elseif ($case === 'traversal') { $zip->addFromString('../outside.xml', 'synthetic');
        } elseif ($case === 'macro') { $zip->addFromString('other/vbaProject.bin', 'synthetic');
        } elseif ($case === 'bomb') { $zip->addFromString('unused.xml', str_repeat('x', 9 * 1024 * 1024));
        } elseif ($case === 'xxe') { $zip->addFromString('word/settings.xml', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///nonexistent">]><x>&x;</x>');
        } elseif ($case === 'symlink') {
            $zip->addFromString('link', 'outside');
            $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        $zip->close();
        $check($rejects(fn() => CvContent::wordText($path, collect: false)), 'Reject unsafe DOCX ' . $case);
    }
    sampleCvDocx($work . '/valid.docx', ['Skills: Teaching']);
    $check(str_contains(CvContent::wordText($work . '/valid.docx'), 'Teaching'), 'Accept simple DOCX.');
    $zip = new ZipArchive();
    $zip->open($work . '/valid.docx');
    $zip->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" TargetMode="External" Target="https://example.invalid/cv"/></Relationships>');
    $zip->close();
    $check(str_contains(CvContent::wordText($work . '/valid.docx'), 'Teaching'), 'Retain ordinary HTTPS hyperlinks.');
    $zip = new ZipArchive();
    $zip->open($work . '/symlink.epub', ZipArchive::CREATE);
    $zip->addFromString('mimetype', 'application/epub+zip');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles><rootfile full-path="book.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
    $zip->addFromString('book.opf', '<package xmlns="http://www.idpf.org/2007/opf" version="3.0"><metadata/><manifest><item id="x" href="chapter.xhtml" media-type="application/xhtml+xml"/></manifest><spine><itemref idref="x"/></spine></package>');
    $zip->addFromString('chapter.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body>Book</body></html>');
    $zip->addFromString('link', 'outside');
    $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    $check($rejects(fn() => $files->validateMaterial($work . '/symlink.epub', 'epub')), 'Reject EPUB archive symlink.');
    $documents = new TeacherDocuments(new TeacherRepository(static fn() => throw new RuntimeException('No database expected.')), $work . '/documents', $work . '/public');
    $key = str_repeat('a', 64);
    $path = $documents->privatePath($key);
    $rows = new class {
        public array $row = [];
        public function prepare(string $sql): self { return $this; }
        public function execute(array $parameters): bool { return true; }
        public function fetch(): array { return $this->row; }
    };
    $downloads = new TeacherDocuments(new TeacherRepository(static fn() => $rows), $work . '/documents', $work . '/public');
    foreach (['valid.pdf' => 'application/pdf', 'action.pdf' => 'application/pdf', 'attachment.pdf' => 'application/pdf', 'valid.docx' => CvContent::DOCX, 'external-template.docx' => CvContent::DOCX] as $source => $media) {
        $body = file_get_contents($work . '/' . $source);
        file_put_contents($path, $body);
        $rows->row = ['storage_key' => $key, 'bytes' => strlen($body), 'media_type' => $media, 'display_name' => $source];
        $response = $downloads->download(1, 1);
        $valid = str_starts_with($source, 'valid.');
        $check($valid ? $response?->body === $body : $response === null, 'Stored teacher original policy: ' . $source);
    }
    unlink($path);
    mkdir($work . '/library');
    foreach (['valid.pdf', 'action.pdf', 'attachment.pdf'] as $source) {
        $body = file_get_contents($work . '/' . $source);
        file_put_contents($work . '/library/' . $key, $body);
        $read = fn() => $files->read($key, strlen($body), hash('sha256', $body), 'application/pdf');
        $check($source === 'valid.pdf' ? $read() === $body : $rejects($read), 'Stored library PDF policy: ' . $source);
    }
    mkdir($path);
    $check($rejects(fn() => $documents->privatePath($key)), 'Reject nonregular teacher key file.');
    rmdir($path);
    if (@symlink($work . '/valid.pdf', $path)) {
        $check($rejects(fn() => $documents->privatePath($key)), 'Reject teacher key symlink.');
        unlink($path);
    } else { echo "SKIP filesystem symlink privilege unavailable.\n"; }
    foreach ($failures as $failure) { fwrite(STDERR, "FAIL $failure\n"); }
    if ($failures === []) { echo "PASS $checks private file security checks.\n"; }
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($work);
}
exit($failures === [] ? 0 : 1);
