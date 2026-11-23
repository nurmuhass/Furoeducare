<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Library;

use Furoeducare\Modules\Teacher\Cv\CvContent;
use InvalidArgumentException;
use RuntimeException;
use XMLReader;
use ZipArchive;

/** Stores opaque, verified files outside the document root. Archives are never extracted. */
final class LibraryFiles
{
    private const MATERIAL_MAX = 20 * 1024 * 1024;
    private const COVER_MAX = 3 * 1024 * 1024;

    public function __construct(private readonly string $directory, private readonly string $publicDirectory) {}

    public function storeMaterial(array $upload): array
    {
        [$path, $extension] = $this->upload($upload, ['pdf', 'epub'], self::MATERIAL_MAX);
        $metadata = $this->validateMaterial($path, $extension);
        $bytes = @file_get_contents($path, length: self::MATERIAL_MAX + 1);
        if (!is_string($bytes) || strlen($bytes) !== $metadata['bytes'] || !hash_equals($metadata['sha256'], hash('sha256', $bytes))) {
            throw new InvalidArgumentException('The uploaded material is invalid.');
        }
        return $this->persist($bytes, $metadata);
    }

    public function storeCover(array $upload): array
    {
        [$path, $extension] = $this->upload($upload, ['jpg', 'jpeg', 'png'], self::COVER_MAX);
        $expected = $extension === 'png' ? 'image/png' : 'image/jpeg';
        $info = @getimagesize($path);
        if ($info === false || ($info['mime'] ?? '') !== $expected || $this->mime($path) !== $expected
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 5000 || $info[1] > 5000 || $info[0] * $info[1] > 20000000) {
            throw new InvalidArgumentException('The cover must be a valid JPEG or PNG image.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Cover image processing is unavailable.');
        }
        $source = @file_get_contents($path, length: self::COVER_MAX + 1);
        $image = is_string($source) ? @imagecreatefromstring($source) : false;
        if ($image === false) {
            throw new InvalidArgumentException('The cover must be a valid JPEG or PNG image.');
        }
        ob_start();
        try {
            if ($expected === 'image/png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $success = imagepng($image, null, 6);
            } else {
                $success = imagejpeg($image, null, 90);
            }
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!$success || !is_string($bytes) || strlen($bytes) > self::COVER_MAX) {
            throw new InvalidArgumentException('The processed cover exceeds the size limit.');
        }
        return $this->persist($bytes, ['media_type' => $expected, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
    }

    public function validateMaterial(string $path, string $extension): array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (is_link($path) || !is_file($path) || !is_int($size) || $size < 1 || $size > self::MATERIAL_MAX) {
            throw new InvalidArgumentException('The material must be a PDF or EPUB of at most 20 MiB.');
        }
        if ($extension === 'pdf' && $this->mime($path) === 'application/pdf' && CvContent::isPdf($path, $size)) {
            $type = 'application/pdf';
        } elseif ($extension === 'epub' && in_array($this->mime($path), ['application/epub+zip', 'application/zip', 'application/x-zip'], true)) {
            if (@file_get_contents($path, length: 4) !== "PK\x03\x04") {
                throw new InvalidArgumentException('The EPUB archive header is invalid.');
            }
            $this->validateEpub($path);
            $type = 'application/epub+zip';
        } else {
            throw new InvalidArgumentException('Choose a valid EPUB or a PDF without active content or compressed objects. Re-export unsupported PDFs.');
        }
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash)) { throw new RuntimeException('The material could not be read.'); }
        return ['extension' => $extension, 'media_type' => $type, 'bytes' => $size, 'sha256' => $hash];
    }

    public function read(string $key, int $expectedBytes, string $expectedHash, ?string $mediaType = null): string
    {
        $this->key($key);
        if ($expectedBytes < 1 || $expectedBytes > self::MATERIAL_MAX || preg_match('/\A[0-9a-f]{64}\z/D', $expectedHash) !== 1) {
            throw new RuntimeException('The library file is unavailable.');
        }
        $path = $this->privateDirectory(false) . '/' . $key;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('The library file is unavailable.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('The library file is unavailable.'); }
        try {
            $opened = fstat($stream);
            if ($opened === false || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']
                || $opened['size'] !== $expectedBytes || ($opened['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('The library file is unavailable.');
            }
            $bytes = stream_get_contents($stream, self::MATERIAL_MAX + 1);
        } finally { fclose($stream); }
        if (!is_string($bytes) || strlen($bytes) !== $expectedBytes || !hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('The library file is unavailable.');
        }
        if ($mediaType === 'application/pdf' && !CvContent::pdfBytes($bytes)) {
            throw new RuntimeException('The library file is unavailable.');
        }
        if ($mediaType !== null && !in_array($mediaType, ['application/pdf', 'application/epub+zip', 'image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('The library file is unavailable.');
        }
        return $bytes;
    }

    public function delete(string $key): bool
    {
        try {
            $this->key($key);
            $path = $this->privateDirectory(false) . '/' . $key;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false) { return true; }
            if (is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || !@unlink($path)) {
                throw new RuntimeException('Delete failed.');
            }
            return true;
        } catch (\Throwable) {
            error_log('Library file cleanup failed.');
            return false;
        }
    }

    private function upload(array $upload, array $extensions, int $limit): array
    {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)
            || !is_string($upload['name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException('A valid uploaded file is required.');
        }
        $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $size = @filesize($upload['tmp_name']);
        if (!in_array($extension, $extensions, true) || !is_int($size) || $size < 1 || $size > $limit) {
            throw new InvalidArgumentException('The uploaded file type or size is invalid.');
        }
        return [$upload['tmp_name'], $extension];
    }

    private function persist(string $bytes, array $metadata): array
    {
        $directory = $this->privateDirectory(true);
        $key = bin2hex(random_bytes(32));
        $temporary = $directory . '/.' . bin2hex(random_bytes(32));
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) { throw new RuntimeException('The uploaded file could not be stored.'); }
        try {
            if (!@chmod($temporary, 0600)) { throw new RuntimeException('The uploaded file could not be secured.'); }
            $written = 0;
            while ($written < strlen($bytes)) {
                $count = fwrite($stream, substr($bytes, $written));
                if ($count === false || $count === 0) { throw new RuntimeException('The uploaded file could not be stored.'); }
                $written += $count;
            }
            if (!fflush($stream)) { throw new RuntimeException('The uploaded file could not be stored.'); }
            fclose($stream);
            $stream = null;
            if ($directory !== $this->privateDirectory(false) || file_exists($directory . '/' . $key)
                || is_link($directory . '/' . $key) || !@rename($temporary, $directory . '/' . $key)) {
                throw new RuntimeException('The uploaded file could not be stored.');
            }
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if (is_file($temporary)) { @unlink($temporary); }
        }
        return ['storage_key' => $key] + $metadata;
    }

    private function privateDirectory(bool $create): string
    {
        $directory = $this->canonical($this->directory);
        $public = $this->canonical($this->publicDirectory);
        $fold = static fn(string $path): string => DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        if ($fold($directory) === $fold($public) || str_starts_with($fold($directory) . '/', rtrim($fold($public), '/') . '/')) {
            throw new RuntimeException('Library storage must be private.');
        }
        if ($create && !is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Library storage is unavailable.');
        }
        if ($create) {
            if ($this->canonical($directory) !== $directory || !@chmod($directory, 0700)) {
                throw new RuntimeException('Library storage is unavailable.');
            }
        }
        return $directory;
    }

    /** Resolve the nearest existing ancestor before appending any new directories. */
    private function canonical(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) { throw new RuntimeException('Library storage is unavailable.'); }
        $path = str_replace('\\', '/', $path);
        if (preg_match('~(?:^|/)\.\.?(/|$)~', $path) === 1) { throw new RuntimeException('Library storage is unavailable.'); }
        $pending = [];
        while (!file_exists($path) && !is_link($path)) {
            $parent = dirname($path);
            if ($parent === $path || $path === '.' || $path === '') { throw new RuntimeException('Library storage is unavailable.'); }
            array_unshift($pending, basename($path));
            $path = $parent;
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) { throw new RuntimeException('Library storage is unavailable.'); }
        return rtrim(str_replace('\\', '/', $resolved), '/') . ($pending === [] ? '' : '/' . implode('/', $pending));
    }

    private function key(string $key): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $key) !== 1) { throw new RuntimeException('The library file is unavailable.'); }
    }

    private function mime(string $path): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    }

    private function archivePath(string $name): bool
    {
        return $name !== '' && !str_contains($name, '\\') && !str_contains($name, "\0")
            && !str_starts_with($name, '/') && !str_contains($name, ':')
            && preg_match('~(?:^|/)\.\.?(?:/|$)~', $name) !== 1 && !str_contains($name, '//');
    }

    /** Resolve an OPF URL within the archive, without ever touching the filesystem. */
    private function manifestTarget(string $package, string $href): string
    {
        $href = rawurldecode($href);
        if ($href === '' || str_contains($href, '\\') || str_contains($href, ':') || str_starts_with($href, '/')
            || str_contains($href, '?') || str_contains($href, '#') || preg_match('/[\x00-\x1f\x7f]/', $href) === 1) {
            throw new InvalidArgumentException('The EPUB manifest reference is invalid.');
        }
        $directory = dirname($package);
        $segments = $directory === '.' ? [] : explode('/', $directory);
        foreach (explode('/', $href) as $segment) {
            if ($segment === '.' || $segment === '') { continue; }
            if ($segment === '..') {
                if ($segments === []) { throw new InvalidArgumentException('The EPUB manifest reference escapes the archive.'); }
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $target = implode('/', $segments);
        if (!$this->archivePath($target)) { throw new InvalidArgumentException('The EPUB manifest reference is invalid.'); }
        return $target;
    }

    private function validateEpub(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('The EPUB archive is invalid.');
        }
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > 2000) { throw new InvalidArgumentException('The EPUB archive is invalid.'); }
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $system = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($i, $system, $attributes);
                if ($stat === false || !$this->archivePath($stat['name']) || isset($names[$stat['name']])
                    || ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000)
                    || ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE
                    || $stat['size'] > self::MATERIAL_MAX || $stat['size'] < 0) {
                    throw new InvalidArgumentException('The EPUB archive is invalid.');
                }
                $names[$stat['name']] = true;
                $total += $stat['size'];
                if ($total > 100 * 1024 * 1024) { throw new InvalidArgumentException('The EPUB archive is too large.'); }
            }
            $mimetype = $zip->statIndex(0);
            if ($mimetype === false || $mimetype['name'] !== 'mimetype' || $mimetype['comp_method'] !== ZipArchive::CM_STORE
                || $this->part($zip, 'mimetype', 64) !== 'application/epub+zip') {
                throw new InvalidArgumentException('The EPUB mimetype is invalid.');
            }
            $packagePaths = [];
            $rootfiles = false;
            $this->xml($this->part($zip, 'META-INF/container.xml', 1024 * 1024), 'container', 'urn:oasis:names:tc:opendocument:xmlns:container',
                function (XMLReader $xml) use (&$packagePaths, &$rootfiles): void {
                    if ($xml->namespaceURI !== 'urn:oasis:names:tc:opendocument:xmlns:container') { return; }
                    if ($xml->depth === 0 && $xml->nodeType === XMLReader::ELEMENT && $xml->getAttribute('version') !== '1.0') {
                        throw new InvalidArgumentException('The EPUB container version is invalid.');
                    }
                    if ($xml->depth === 1) {
                        $rootfiles = $xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'rootfiles';
                    }
                    if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'rootfile'
                        && $xml->depth === 2 && $rootfiles
                        && $xml->getAttribute('media-type') === 'application/oebps-package+xml') {
                        $name = $xml->getAttribute('full-path') ?? '';
                        if (!$this->archivePath($name) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'opf') {
                            throw new InvalidArgumentException('The EPUB package path is invalid.');
                        }
                        $packagePaths[$name] = true;
                    }
                });
            if ($packagePaths === []) { throw new InvalidArgumentException('The EPUB package is missing.'); }
            foreach (array_keys($packagePaths) as $name) {
                $sections = [];
                $items = [];
                $references = [];
                $section = '';
                $this->xml($this->part($zip, $name, 2 * 1024 * 1024), 'package', 'http://www.idpf.org/2007/opf',
                    function (XMLReader $xml) use (&$sections, &$items, &$references, &$section, $name, $names): void {
                        if ($xml->nodeType !== XMLReader::ELEMENT || $xml->namespaceURI !== 'http://www.idpf.org/2007/opf') { return; }
                        if ($xml->depth === 0 && !in_array($xml->getAttribute('version'), ['2.0', '3.0'], true)) {
                            throw new InvalidArgumentException('The EPUB package version is invalid.');
                        }
                        if ($xml->depth === 1) {
                            $section = $xml->localName;
                            if (in_array($section, ['metadata', 'manifest', 'spine'], true)) { $sections[$section] = true; }
                        }
                        if ($xml->localName === 'item' && $xml->depth === 2 && $section === 'manifest') {
                            $id = $xml->getAttribute('id') ?? '';
                            $target = $this->manifestTarget($name, $xml->getAttribute('href') ?? '');
                            if ($id === '' || isset($items[$id])
                                || !isset($names[$target]) || !$xml->getAttribute('media-type')) {
                                throw new InvalidArgumentException('The EPUB manifest is invalid.');
                            }
                            $items[$id] = true;
                        }
                        if ($xml->localName === 'itemref' && $xml->depth === 2 && $section === 'spine') { $references[] = $xml->getAttribute('idref') ?? ''; }
                    });
                if (count($sections) !== 3 || $items === [] || $references === []) { throw new InvalidArgumentException('The EPUB package is invalid.'); }
                foreach ($references as $reference) {
                    if (!isset($items[$reference])) { throw new InvalidArgumentException('The EPUB spine is invalid.'); }
                }
            }
        } finally { $zip->close(); }
    }

    private function part(ZipArchive $zip, string $name, int $max): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] < 1 || $stat['size'] > $max) { throw new InvalidArgumentException('An EPUB component is invalid.'); }
        $bytes = $zip->getFromIndex($stat['index'], $max + 1);
        if (!is_string($bytes) || strlen($bytes) !== $stat['size']) { throw new InvalidArgumentException('An EPUB component is invalid.'); }
        return $bytes;
    }

    private function xml(string $bytes, string $root, string $namespace, callable $handle): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = null;
        $found = false;
        try {
            $reader = XMLReader::fromString($bytes, flags: LIBXML_NONET);
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY_REF], true)) { throw new InvalidArgumentException('EPUB XML declarations are invalid.'); }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0) {
                    if ($found || $reader->localName !== $root || $reader->namespaceURI !== $namespace) { throw new InvalidArgumentException('An EPUB XML root is invalid.'); }
                    $found = true;
                }
                $handle($reader);
            }
            if (!$found || libxml_get_last_error() !== false) { throw new InvalidArgumentException('An EPUB XML component is invalid.'); }
        } finally {
            $reader?->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
