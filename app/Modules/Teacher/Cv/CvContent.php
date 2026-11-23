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
        if ($bytes < 1 || $bytes > 20 * 1024 * 1024) { return false; }
        $content = @file_get_contents($path, length: $bytes + 1);
        return is_string($content) && strlen($content) === $bytes && self::pdfBytes($content);
    }

    public static function pdfBytes(#[\SensitiveParameter] string $content): bool
    {
        $bytes = strlen($content);
        if ($bytes < 1 || $bytes > 20 * 1024 * 1024) { return false; }
        $header = substr($content, 0, 16);
        $tail = substr($content, max(0, $bytes - 2048));
        if (preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)[\r\n]/', $header) !== 1
            || preg_match('/startxref[ \t\r\n]+([0-9]{1,8})[ \t\r\n]+%%EOF[ \t\r\n]*\z/', $tail, $match) !== 1) {
            return false;
        }
        $offset = (int) $match[1];
        if ($offset <= 0 || $offset >= $bytes) {
            return false;
        }
        $xref = substr($content, $offset, 128);
        if (preg_match('/\A\s*(?:xref\b|[0-9]+\s+[0-9]+\s+obj\b)/', $xref) !== 1) {
            return false;
        }
        // Conservative format policy, not an antivirus or a complete PDF parser.
        // Opaque object streams and encryption can hide action dictionaries; require re-export.
        $offset = 0;
        $count = 0;
        while (preg_match('~/([^\x00\x09\x0a\x0c\x0d\x20()<>\[\]{}/%]{1,4097})~', $content, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            if (++$count > 200000) { return false; }
            $name = $match[1][0];
            if (strlen($name) > 4096) { return false; }
            $offset = $match[0][1] + strlen($match[0][0]);
            $name = preg_replace_callback('/#([0-9a-fA-F]{2})/', static fn(array $m): string => chr(hexdec($m[1])), $name);
            if (in_array($name, ['JavaScript', 'JS', 'Launch', 'OpenAction', 'AA', 'EmbeddedFiles', 'EmbeddedFile', 'EF', 'FileAttachment',
                'RichMedia', 'XFA', 'SubmitForm', 'ImportData', 'GoToR', 'GoToE', 'Encrypt', 'ObjStm'], true)) {
                return false;
            }
        }
        return true;
    }

    /** Recheck the exact downloaded snapshot, including files uploaded before this policy existed. */
    public static function allowsDownload(#[\SensitiveParameter] string $body, string $mediaType): bool
    {
        if ($body === '' || strlen($body) > 5 * 1024 * 1024) { return false; }
        if ($mediaType === 'application/pdf') { return self::pdfBytes($body); }
        if (in_array($mediaType, ['image/jpeg', 'image/png'], true)) {
            return (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) === $mediaType;
        }
        if ($mediaType !== self::DOCX) { return false; }
        // ZipArchive requires a pathname. Use a private, bounded snapshot rather than reopening
        // the original path after reading it, which could validate different bytes during a race.
        $snapshot = @tmpfile();
        if ($snapshot === false) { return false; }
        try {
            $path = stream_get_meta_data($snapshot)['uri'];
            if (!@chmod($path, 0600) || fwrite($snapshot, $body) !== strlen($body) || !fflush($snapshot)) { return false; }
            self::wordText($path, collect: false);
            return true;
        } catch (\Throwable) {
            return false;
        } finally { fclose($snapshot); }
    }

    public static function wordText(string $path, bool $collect = true): string
    {
        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new ExtractionFailure('unreadable');
        }
        try {
            self::validateWordArchive($archive);

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

    private static function validateWordArchive(ZipArchive $archive): void
    {
        if ($archive->numFiles < 2 || $archive->numFiles > 2000) { throw new ExtractionFailure('unreadable'); }
        $total = 0;
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $info = $archive->statIndex($i);
            if ($info === false) { throw new ExtractionFailure('unreadable'); }
            $name = $info['name'];
            $fold = strtolower($name);
            $attributes = 0;
            $system = 0;
            $archive->getExternalAttributesIndex($i, $system, $attributes);
            if (!self::archivePath($name) || isset($names[$fold])
                || ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000)
                || ($info['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE
                || $info['size'] < 0 || $info['size'] > 8 * 1024 * 1024
                || ($total += $info['size']) > 32 * 1024 * 1024
                || preg_match('~(?:^|/)(?:embeddings|activex)(?:/|$)|vbaproject|\.(?:exe|dll|com|scr|bat|cmd|ps1|vbs|js|jar|msi|hta)$~i', $name) === 1) {
                throw new ExtractionFailure('unreadable');
            }
            $names[$fold] = true;
            if (!preg_match('/\.(?:xml|rels)$/i', $name)) { continue; }
            $xml = self::part($archive, $name, 8 * 1024 * 1024);
            self::readXml($xml, static function (XMLReader $reader) use ($name): void {
                if ($reader->nodeType !== XMLReader::ELEMENT) { return; }
                foreach (['ContentType', 'Type'] as $attribute) {
                    $value = strtolower($reader->getAttribute($attribute) ?? '');
                    if (preg_match('~macroenabled|vbaproject|oleobject|activex|/package$|/attachedtemplate$|/control$|/afchunk$~', $value)) {
                        throw new ExtractionFailure('unreadable');
                    }
                }
                if ($reader->localName !== 'Relationship') { return; }
                $target = $reader->getAttribute('Target') ?? '';
                if (strcasecmp($reader->getAttribute('TargetMode') ?? '', 'External') === 0) {
                    // Plain hyperlinks are normal CV content; templates, images and other remote parts are not.
                    if (!str_ends_with($reader->getAttribute('Type') ?? '', '/hyperlink')
                        || preg_match('~\A(?:https?://|mailto:)~i', $target) !== 1
                        || preg_match('/[\x00-\x20\x7f]/', $target)) {
                        throw new ExtractionFailure('unreadable');
                    }
                } else {
                    $target = rawurldecode($target);
                    if ($target === '' || str_contains($target, '\\') || str_contains($target, ':')
                        || preg_match('/[\x00-\x1f\x7f]/', $target)) { throw new ExtractionFailure('unreadable'); }
                    $base = dirname(dirname($name));
                    $segments = str_starts_with($target, '/') || $base === '.' ? [] : explode('/', $base);
                    foreach (explode('/', $target) as $segment) {
                        if ($segment === '' || $segment === '.') { continue; }
                        if ($segment === '..') {
                            if ($segments === []) { throw new ExtractionFailure('unreadable'); }
                            array_pop($segments);
                        } else { $segments[] = $segment; }
                    }
                }
            });
        }
    }

    private static function archivePath(string $name): bool
    {
        return $name !== '' && !str_contains($name, '\\') && !str_contains($name, ':')
            && !str_starts_with($name, '/') && !str_contains($name, '//')
            && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1
            && preg_match('~(?:^|/)\.\.?(?:/|$)~', $name) !== 1;
    }

    private static function readXml(#[\SensitiveParameter] string $part, callable $handle): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = null;
        try {
            $reader = XMLReader::fromString($part, flags: LIBXML_NONET);
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
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
