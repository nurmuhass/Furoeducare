<?php
declare(strict_types=1);

use Furoeducare\Modules\Library\LibraryFiles;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/cv-fixtures.php';

function libraryAssert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function libraryReject(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException | InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
function libraryEpub(string $path, string $extra = '', ?string $container = null): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', 'application/epub+zip');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('META-INF/container.xml', $container ?? '<?xml version="1.0"?><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles><rootfile full-path="OEBPS/book.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
    $zip->addFromString('OEBPS/book.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">book</dc:identifier><dc:title>Book</dc:title><dc:language>en</dc:language></metadata><manifest><item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/></manifest><spine><itemref idref="chapter"/></spine></package>');
    $zip->addFromString('OEBPS/chapter.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body>Book</body></html>');
    if ($extra !== '') { $zip->addFromString($extra, 'bad'); }
    $zip->close();
}

$work = sys_get_temp_dir() . '/furo-library-files-' . bin2hex(random_bytes(8));
mkdir($work, 0700);
mkdir($work . '/public', 0700);
try {
    libraryAssert(class_exists(LibraryFiles::class), 'LibraryFiles must exist.');
    $files = new LibraryFiles($work . '/private/nested', $work . '/public');
    libraryAssert(!is_dir($work . '/private'), 'Constructor does not create storage.');
    file_put_contents($work . '/valid.pdf', sampleCvPdf(['Title', 'A useful book.']));
    $pdf = $files->validateMaterial($work . '/valid.pdf', 'pdf');
    libraryAssert($pdf['media_type'] === 'application/pdf' && $pdf['sha256'] === hash_file('sha256', $work . '/valid.pdf'), 'Validate a structured PDF with its actual hash.');
    file_put_contents($work . '/bad.pdf', "%PDF-1.4\nfake\n%%EOF");
    libraryReject(fn() => $files->validateMaterial($work . '/bad.pdf', 'pdf'), 'Reject fake PDF.');
    libraryReject(fn() => $files->validateMaterial($work . '/valid.pdf', 'epub'), 'Reject extension and content mismatch.');
    libraryEpub($work . '/valid.epub');
    libraryAssert($files->validateMaterial($work . '/valid.epub', 'epub')['media_type'] === 'application/epub+zip', 'Accept structured EPUB.');
    foreach (['../chapter.xhtml', '%2e%2e/chapter.xhtml', '../../../outside.xhtml'] as $relative) {
        libraryEpub($work . '/relative.epub');
        $zip = new ZipArchive();
        $zip->open($work . '/relative.epub');
        $container = $zip->getFromName('META-INF/container.xml');
        $package = $zip->getFromName('OEBPS/book.opf');
        $zip->deleteName('OEBPS/book.opf');
        $zip->addFromString('META-INF/container.xml', str_replace('OEBPS/book.opf', 'OEBPS/package/book.opf', $container));
        $zip->addFromString('OEBPS/package/book.opf', str_replace('href="chapter.xhtml"', 'href="' . $relative . '"', $package));
        $zip->addFromString('outside.xhtml', 'Outside archive-relative package root.');
        $zip->close();
        if ($relative === '../../../outside.xhtml') {
            libraryReject(fn() => $files->validateMaterial($work . '/relative.epub', 'epub'), 'Reject a manifest reference escaping archive root.');
        } else {
            libraryAssert($files->validateMaterial($work . '/relative.epub', 'epub')['media_type'] === 'application/epub+zip', 'Resolve safe package-relative parent references.');
        }
    }
    libraryEpub($work . '/compressed.epub');
    $zip = new ZipArchive();
    $zip->open($work . '/compressed.epub');
    $zip->setCompressionName('mimetype', ZipArchive::CM_DEFLATE);
    $zip->close();
    libraryReject(fn() => $files->validateMaterial($work . '/compressed.epub', 'epub'), 'Reject compressed EPUB mimetype.');
    libraryEpub($work . '/wrong-container.epub', '', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfile full-path="OEBPS/book.opf" media-type="application/oebps-package+xml"/></container>');
    libraryReject(fn() => $files->validateMaterial($work . '/wrong-container.epub', 'epub'), 'Reject malformed EPUB container hierarchy.');
    foreach (['../escape', '/absolute', 'C:/drive', 'OEBPS\\escape'] as $unsafe) {
        libraryEpub($work . '/unsafe.epub', $unsafe);
        libraryReject(fn() => $files->validateMaterial($work . '/unsafe.epub', 'epub'), 'Reject unsafe EPUB path.');
    }
    libraryEpub($work . '/entity.epub', '', '<!DOCTYPE container [<!ENTITY x SYSTEM "file:///secret">]><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/book.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
    libraryReject(fn() => $files->validateMaterial($work . '/entity.epub', 'epub'), 'Reject XML DTD.');
    libraryEpub($work . '/encrypted.epub');
    $zip = new ZipArchive();
    $zip->open($work . '/encrypted.epub');
    libraryAssert($zip->setEncryptionName('OEBPS/chapter.xhtml', ZipArchive::EM_AES_256, 'password'), 'Build encrypted archive fixture.');
    $zip->close();
    libraryReject(fn() => $files->validateMaterial($work . '/encrypted.epub', 'epub'), 'Reject encrypted EPUB entries.');
    libraryEpub($work . '/many.epub');
    $zip->open($work . '/many.epub');
    for ($i = 0; $i < 2000; $i++) { $zip->addFromString('extra/' . $i, 'x'); }
    $zip->close();
    libraryReject(fn() => $files->validateMaterial($work . '/many.epub', 'epub'), 'Reject excessive entry count.');
    libraryEpub($work . '/large.epub');
    $zip->open($work . '/large.epub');
    $zip->addFromString('large', str_repeat('x', 20 * 1024 * 1024 + 1));
    $zip->close();
    libraryReject(fn() => $files->validateMaterial($work . '/large.epub', 'epub'), 'Reject oversized uncompressed entries.');
    libraryEpub($work . '/total.epub');
    $zip->open($work . '/total.epub');
    file_put_contents($work . '/large-part', str_repeat('x', 18 * 1024 * 1024));
    for ($i = 0; $i < 6; $i++) { $zip->addFile($work . '/large-part', 'large/' . $i); }
    $zip->close();
    libraryReject(fn() => $files->validateMaterial($work . '/total.epub', 'epub'), 'Reject oversized uncompressed total.');
    $upload = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $work . '/valid.pdf', 'name' => 'book.pdf', 'size' => filesize($work . '/valid.pdf'), 'type' => 'application/pdf'];
    libraryReject(fn() => $files->storeMaterial($upload), 'Reject local file pretending to be uploaded.');
    libraryReject(fn() => $files->storeCover($upload), 'Reject local cover pretending to be uploaded.');
    mkdir($work . '/private/nested', 0700, true);
    $key = str_repeat('a', 64);
    file_put_contents($work . '/private/nested/' . $key, 'private');
    libraryAssert($files->read($key, 7, hash('sha256', 'private')) === 'private', 'Read verified private bytes.');
    libraryReject(fn() => $files->read($key, 6, hash('sha256', 'private')), 'Reject size mismatch.');
    libraryReject(fn() => $files->read($key, 7, str_repeat('0', 64)), 'Reject hash mismatch.');
    libraryReject(fn() => $files->read('../valid.pdf', 7, str_repeat('0', 64)), 'Reject traversal.');
    if (@symlink($work . '/valid.pdf', $work . '/private/nested/' . str_repeat('b', 64))) {
        libraryReject(fn() => $files->read(str_repeat('b', 64), $pdf['bytes'], $pdf['sha256']), 'Reject a storage key symlink.');
        unlink($work . '/private/nested/' . str_repeat('b', 64));
    }
    if (@symlink($work . '/public', $work . '/public-link')) {
        libraryReject(fn() => (new LibraryFiles($work . '/public-link/deep/nested', $work . '/public'))->read($key, 7, hash('sha256', 'private')), 'Resolve symlinked storage ancestors before checking privacy.');
        unlink($work . '/public-link');
    }
    $public = new LibraryFiles($work . '/public/nested/deeper', $work . '/public');
    libraryReject(fn() => $public->read($key, 7, hash('sha256', 'private')), 'Reject public storage even before nested directory exists.');
    libraryAssert($files->delete($key) && $files->delete($key), 'Deletion is idempotent.');
    $oldLog = ini_set('error_log', $work . '/cleanup.log');
    try { libraryAssert(!$files->delete('../valid.pdf'), 'Deletion rejects traversal.'); }
    finally { ini_set('error_log', $oldLog); }
    libraryAssert(str_contains(file_get_contents($work . '/cleanup.log'), 'Library file cleanup failed.') && !str_contains(file_get_contents($work . '/cleanup.log'), 'valid.pdf'), 'Cleanup failure logs do not disclose file paths.');
    echo "PASS library file content, upload authenticity, private paths and integrity.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($work);
}
