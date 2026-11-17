<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use InvalidArgumentException;

final class ListingPhoto
{
    private const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
    private const MAX_SOURCE_SIDE = 4096;
    private const MAX_SOURCE_PIXELS = 10_000_000;
    private const MAX_PUBLIC_SIDE = 640;

    public static function receive(array $upload, bool $authorised): ?string
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (!$authorised) {
            throw new InvalidArgumentException('Confirm separate authorisation before adding a public photo.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Choose a photo up to 2 MB.');
        }
        $path = $upload['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) {
            throw new InvalidArgumentException('Choose a photo and try uploading it again.');
        }
        $bytes = @filesize($path);
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Choose a nonempty photo up to 2 MB.');
        }
        if (!function_exists('imagejpeg') || !function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')) {
            throw new InvalidArgumentException('Public photo uploads are unavailable. Enable GD to publish a photo.');
        }

        $size = @getimagesize($path);
        if ($size === false || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new InvalidArgumentException('Choose a valid JPEG or PNG photo.');
        }
        [$width, $height, $type] = $size;
        if ($width < 1 || $height < 1 || max($width, $height) > self::MAX_SOURCE_SIDE || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new InvalidArgumentException('Choose a photo up to 4,096 pixels per side and 10 megapixels.');
        }

        $source = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if ($source === false || imagesx($source) !== $width || imagesy($source) !== $height) {
            throw new InvalidArgumentException('Choose a valid JPEG or PNG photo.');
        }
        $scale = min(1, self::MAX_PUBLIC_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false || !imagefill($target, 0, 0, 0xffffff)
            || !imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            throw new InvalidArgumentException('This public photo could not be processed.');
        }

        // Encoding a new image from pixels drops the original file name, metadata and trailing bytes.
        ob_start();
        try {
            $encoded = @imagejpeg($target, null, 85);
            $jpeg = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!$encoded || $jpeg === '' || strlen($jpeg) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('This public photo could not be processed.');
        }
        return $jpeg;
    }
}
