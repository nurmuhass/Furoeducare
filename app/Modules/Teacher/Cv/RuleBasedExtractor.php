<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

use PrinsFrank\PdfParser\PdfParser;

final class RuleBasedExtractor implements CvExtractor
{
    public const DOCX = CvContent::DOCX;
    public const MAX_TEXT_BYTES = 200_000;

    public function __construct(private readonly CvParser $parser)
    {
    }

    public function extract(string $path, string $mediaType): array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (is_link($path) || !is_file($path) || !is_readable($path) || !is_int($size)
            || $size < 1 || $size > 5 * 1024 * 1024
            || ($mediaType === 'application/pdf' && !CvContent::isPdf($path, $size))) {
            throw new ExtractionFailure('unreadable');
        }

        $text = match ($mediaType) {
            'application/pdf' => (new PdfParser())->parseFile($path)->getText(),
            self::DOCX => CvContent::wordText($path),
            default => throw new ExtractionFailure('unreadable'),
        };
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            $text = substr($text, 0, self::MAX_TEXT_BYTES);
            for ($trim = 0; $trim < 3 && preg_match('//u', $text) !== 1; $trim++) {
                $text = substr($text, 0, -1);
            }
        }
        if (preg_match('//u', $text) !== 1) {
            throw new ExtractionFailure('unreadable');
        }
        if (preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            $bytes = $mediaType === 'application/pdf' ? file_get_contents($path) : '';
            $imageOnly = is_string($bytes) && preg_match('~/Subtype\s*/Image\b~', $bytes) === 1;
            throw new ExtractionFailure($imageOnly ? 'scanned_pdf' : 'no_text');
        }
        return CvDraft::validate($this->parser->parse($text));
    }
}
