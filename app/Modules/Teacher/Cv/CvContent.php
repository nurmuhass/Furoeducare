<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

use XMLReader;
use ZipArchive;

final class CvContent
{
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const WORD_NS = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'http://purl.oclc.org/ooxml/wordprocessingml/main',
    ];
    private const TYPE_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';

    public static function isPdf(string $path, int $bytes): bool
    {
        $header = file_get_contents($path, length: 16);
        $tail = file_get_contents($path, offset: max(0, $bytes - 2048), length: 2048);
        if (!is_string($header) || preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)[\r\n]/', $header) !== 1
            || !is_string($tail) || preg_match('/startxref[ \t\r\n]+([0-9]{1,8})[ \t\r\n]+%%EOF[ \t\r\n]*\z/', $tail, $match) !== 1) {
            return false;
        }
        $offset = (int) $match[1];
        if ($offset <= 0 || $offset >= $bytes) {
            return false;
        }
        $xref = file_get_contents($path, offset: $offset, length: 128);
        return is_string($xref) && preg_match('/\A\s*(?:xref\b|[0-9]+\s+[0-9]+\s+obj\b)/', $xref) === 1;
    }

    public static function wordText(string $path, bool $collect = true): string
    {
        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new ExtractionFailure('unreadable');
        }
        try {
            if ($archive->locateName('word/vbaProject.bin', ZipArchive::FL_NOCASE) !== false) {
                throw new ExtractionFailure('unreadable');
            }

            $manifest = self::part($archive, '[Content_Types].xml', 256 * 1024);
            $document = self::part($archive, 'word/document.xml', 8 * 1024 * 1024);
        } finally {
            $archive->close();
        }

        $wordPart = false;
        self::readXml($manifest, static function (XMLReader $xml) use (&$wordPart): void {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->namespaceURI === self::TYPE_NS
                && $xml->localName === 'Override' && $xml->getAttribute('PartName') === '/word/document.xml'
                && $xml->getAttribute('ContentType') === self::DOCX . '.main+xml') {
                $wordPart = true;
            }
        });
        if (!$wordPart) {
            throw new ExtractionFailure('unreadable');
        }

        $body = false;
        $text = '';
        self::readXml($document, static function (XMLReader $xml) use (&$body, &$text, $collect): void {
            if ($xml->depth === 0 && $xml->nodeType === XMLReader::ELEMENT
                && ($xml->localName !== 'document' || !in_array($xml->namespaceURI, self::WORD_NS, true))) {
                throw new ExtractionFailure('unreadable');
            }
            if (!in_array($xml->namespaceURI, self::WORD_NS, true)) {
                return;
            }
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'body' && $xml->depth === 1) {
                $body = true;
            }
            if (!$collect || !$body || strlen($text) >= RuleBasedExtractor::MAX_TEXT_BYTES) {
                return;
            }
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 't') {
                $text .= $xml->readString();
            } elseif (($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'p')
                || ($xml->nodeType === XMLReader::ELEMENT && in_array($xml->localName, ['br', 'cr', 'tab'], true))) {
                $text .= "\n";
            }
        });
        if (!$body) {
            throw new ExtractionFailure('unreadable');
        }
        return $text;
    }

    private static function part(ZipArchive $archive, string $name, int $max): string
    {
        $info = $archive->statName($name);
        if ($info === false || $info['size'] < 1 || $info['size'] > $max
            || ($info['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
            throw new ExtractionFailure('unreadable');
        }
        $bytes = $archive->getFromIndex($info['index'], $max + 1);
        if ($bytes === false || strlen($bytes) !== $info['size']) {
            throw new ExtractionFailure('unreadable');
        }
        return $bytes;
    }

    private static function readXml(#[\SensitiveParameter] string $part, callable $handle): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = null;
        try {
            $reader = XMLReader::fromString($part, flags: LIBXML_NONET);
            while ($reader->read()) {
                if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY_REF], true)) {
                    throw new ExtractionFailure('unreadable');
                }
                $handle($reader);
            }
            if (libxml_get_last_error() !== false) {
                throw new ExtractionFailure('unreadable');
            }
        } catch (\ValueError | \RuntimeException) {
            throw new ExtractionFailure('unreadable');
        } finally {
            $reader?->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
