<?php

declare(strict_types=1);

/** Assemble a PDF with actual page content, offsets and a cross-reference table. */
function sampleCvPdf(array $lines, bool $imageOnly = false, string $catalogEntries = ''): string
{
    $content = $imageOnly ? 'q 100 0 0 100 20 20 cm /Im0 Do Q' : "BT /F1 11 Tf 14 TL 45 760 Td\n";
    foreach ($lines as $line) {
        $content .= '(' . addcslashes($line, "\\()") . ") Tj T*\n";
    }
    if (!$imageOnly) {
        $content .= 'ET';
    }
    $resource = $imageOnly ? '/XObject << /Im0 6 0 R >>' : '/Font << /F1 4 0 R >>';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R ' . $catalogEntries . ' >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << $resource >> /Contents 5 0 R >>",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($content) . " >>\nstream\n$content\nendstream",
    ];
    if ($imageOnly) {
        $objects[] = "<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 1 >>\nstream\n\x00\nendstream";
    }
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

function sampleCvDocx(string $path, array $lines): void
{
    $archive = new ZipArchive();
    expect($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create synthetic DOCX.');
    $archive->addFromString('[Content_Types].xml',
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $archive->addFromString('_rels/.rels',
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
    foreach ($lines as $line) {
        $xml .= '<w:p><w:r><w:t>' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p>';
    }
    $archive->addFromString('word/document.xml', $xml . '</w:body></w:document>');
    $archive->close();
}
