<?php

declare(strict_types=1);

require_once __DIR__ . '/cv-fixtures.php';

function libraryFields(array $replace = []): array
{
    return array_replace([
        'action' => 'save', 'title' => 'Library algebra workbook', 'author' => 'Library Author',
        'description' => 'Exercises for independent study.', 'publisher' => 'Fixture Press',
        'publication_year' => '2026', 'edition' => 'First', 'isbn' => '', 'access' => 'free',
        'licence' => 'CC BY 4.0', 'rights_reference' => 'Private permission reference LIB-123',
        'rights_confirmed' => '1', 'categories' => "Workbooks\nMathematics", 'subjects' => 'Algebra',
        'levels' => 'Junior secondary',
    ], $replace);
}

/** Submit actual multipart uploads through PHP's upload handling. */
function libraryUpload(string $base, AuthBrowser $browser, string $page, array $fields, array $files = [], bool $csrf = true): array
{
    if ($csrf) {
        $fields['_csrf'] = $browser->form($page);
    }
    $boundary = 'library-' . bin2hex(random_bytes(16));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    }
    foreach ($files as $name => [$filename, $type, $bytes]) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\nContent-Type: $type\r\n\r\n$bytes\r\n";
    }
    return request($base, '/dashboard/admin/library', 'POST', $body . "--$boundary--\r\n", $browser->cookie,
        'multipart/form-data; boundary=' . $boundary);
}

function libraryEpub(string $work): string
{
    $path = $work . '/fixture.epub';
    $zip = new ZipArchive();
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Create EPUB fixture.');
    $zip->addFromString('mimetype', 'application/epub+zip');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
    $zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?><package version="3.0" unique-identifier="book" xmlns="http://www.idpf.org/2007/opf"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="book">urn:uuid:library-fixture</dc:identifier><dc:title>Fixture EPUB</dc:title><dc:language>en</dc:language></metadata><manifest><item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/></manifest><spine><itemref idref="chapter"/></spine></package>');
    $zip->addFromString('OEBPS/chapter.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Chapter</title></head><body><p>Library fixture.</p></body></html>');
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);
    return $bytes;
}
