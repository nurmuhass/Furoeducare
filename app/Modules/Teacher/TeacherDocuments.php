<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

use finfo;
use Furoeducare\Core\Response;
use Furoeducare\Modules\Teacher\Cv\CvContent;
use Furoeducare\Modules\Teacher\Cv\ExtractionFailure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;


final class TeacherDocuments
{
    public const KINDS = ['cv' => 'CV', 'qualification' => 'Qualification', 'certification' => 'Certification', 'other' => 'Other professional document'];
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const SUPPORTING_LIMIT = 20;

    public function __construct(
        private readonly TeacherRepository $repository,
        private readonly string $directory,
        private readonly string $publicDirectory,
    ) {
    }

    public function upload(int $teacher, string $kind, array $upload): void
    {
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('Select a document type.');
        }
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        $temporary = $upload['tmp_name'] ?? null;
        $name = $upload['name'] ?? null;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Choose a file no larger than 5 MB.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_string($name) || !is_string($temporary) || !is_uploaded_file($temporary)) {
            throw new InvalidArgumentException('Choose a file and try uploading it again.');
        }
        $bytes = filesize($temporary);
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('Choose a nonempty file no larger than 5 MB.');
        }

        $name = basename(str_replace('\\', '/', $name));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $mime = match ($extension) {
            'pdf' => $detected === 'application/pdf' && CvContent::isPdf($temporary, $bytes) ? $detected : null,
            'docx' => in_array($detected, [CvContent::DOCX, 'application/zip'], true)
                && $this->isWordDocument($temporary) ? CvContent::DOCX : null,
            'jpg', 'jpeg' => $kind !== 'cv' && $detected === 'image/jpeg' ? $detected : null,
            'png' => $kind !== 'cv' && $detected === 'image/png' ? $detected : null,
            default => null,
        };
        if ($mime === null) {
            throw new InvalidArgumentException($kind === 'cv'
                ? 'Choose a valid DOCX or a PDF without active content or compressed objects. Re-export unsupported PDFs, or enter your profile manually.'
                : 'Choose a valid DOCX, JPEG, PNG or a PDF without active content or compressed objects. Re-export unsupported PDFs.');
        }

        $base = preg_replace('/[^A-Za-z0-9._ -]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $name = (trim(substr($base, 0, 170), '. ') ?: 'document') . '.' . $extension;
        $key = bin2hex(random_bytes(32));
        $path = $this->privatePath($key);
        $this->retryRemovals($teacher);
        if (!move_uploaded_file($temporary, $path)) {
            throw new RuntimeException('Unable to store the private teacher document.');
        }
        if (!@chmod($path, 0600)) {
            $this->deleteFile($key);
            throw new RuntimeException('Unable to secure the private teacher document.');
        }

        try {
            $this->repository->addDocument($teacher, $kind, $key, $name, $mime, $bytes);
        } catch (Throwable $error) {
            $this->deleteFile($key);
            throw $error;
        }
        $this->retryRemovals($teacher);
    }

    public function download(int $teacher, int $id): ?Response
    {
        $document = $this->repository->document($teacher, $id);
        if ($document === null) {
            return null;
        }
        $path = $this->privatePath($document['storage_key']);
        $stat = @lstat($path);
        $stream = @fopen($path, 'rb');
        $body = false;
        if ($stream !== false) {
            try {
                $opened = fstat($stream);
                if ($stat !== false && $opened !== false && $opened['ino'] === $stat['ino'] && $opened['dev'] === $stat['dev']
                    && ($opened['mode'] & 0170000) === 0100000 && $opened['size'] === (int) $document['bytes']) {
                    $body = stream_get_contents($stream, self::MAX_BYTES + 1);
                }
            } finally { fclose($stream); }
        }
        if ($body === false || strlen($body) !== (int) $document['bytes']) {
            if ($this->repository->document($teacher, $id) === null) {
                return null;
            }
            throw new RuntimeException('Unable to read the private teacher document.');
        }
        if (!CvContent::allowsDownload($body, $document['media_type'])) {
            return null;
        }

        return new Response($body, 200, [
            'Content-Type' => $document['media_type'],
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $document['display_name']) . '"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function remove(int $teacher, int $id): bool
    {
        $removed = $this->repository->removeDocument($teacher, $id);
        $this->retryRemovals($teacher);
        return $removed;
    }

    private function retryRemovals(int $teacher): void
    {
        foreach ($this->repository->pendingDocumentKeys($teacher) as $key) {
            if ($this->deleteFile($key)) {
                $this->repository->finishDocumentRemoval($teacher, $key);
            }
        }
    }

    private function isWordDocument(string $path): bool
    {
        try {
            CvContent::wordText($path, collect: false);
            return true;
        } catch (ExtractionFailure) {
            return false;
        }
    }

    public function privatePath(string $key): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1) {
            throw new RuntimeException('Invalid teacher document storage key.');
        }
        $parent = realpath(dirname($this->directory));
        $public = realpath($this->publicDirectory);
        if ($parent === false || $public === false || $this->inside($parent, $public)) {
            throw new RuntimeException('The teacher document directory requires an existing private parent directory.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the private teacher document directory.');
        }
        $directory = realpath($this->directory);
        if ($directory === false || $this->inside($directory, $public)) {
            throw new RuntimeException('Teacher documents must be stored outside the public directory.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $key;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (is_link($path) || ($stat !== false && ($stat['mode'] & 0170000) !== 0100000)) {
            throw new RuntimeException('Invalid teacher document file.');
        }
        return $path;
    }

    private function inside(string $path, string $directory): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }
        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    private function deleteFile(string $key): bool
    {
        $path = $this->privatePath($key);
        if (@unlink($path)) {
            return true;
        }
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return true;
        }
        error_log('Furoeducare could not remove a private teacher document.');
        return false;
    }
}
