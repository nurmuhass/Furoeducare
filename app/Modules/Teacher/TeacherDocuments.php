<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

use finfo;
use Furoeducare\Core\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class TeacherDocuments
{
    public const KINDS = ['cv' => 'CV', 'qualification' => 'Qualification', 'certification' => 'Certification', 'other' => 'Other professional document'];
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const SUPPORTING_LIMIT = 20;
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

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
            'pdf' => $detected === 'application/pdf' ? $detected : null,
            'docx' => in_array($detected, [self::DOCX, 'application/zip'], true)
                && $this->isWordDocument($temporary) ? self::DOCX : null,
            'jpg', 'jpeg' => $kind !== 'cv' && $detected === 'image/jpeg' ? $detected : null,
            'png' => $kind !== 'cv' && $detected === 'image/png' ? $detected : null,
            default => null,
        };
        if ($mime === null) {
            throw new InvalidArgumentException($kind === 'cv'
                ? 'Choose a valid PDF or DOCX file for your CV.'
                : 'Choose a valid PDF, DOCX, JPEG or PNG file.');
        }

        $base = preg_replace('/[^A-Za-z0-9._ -]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $name = (trim(substr($base, 0, 170), '. ') ?: 'document') . '.' . $extension;
        $key = bin2hex(random_bytes(32));
        $path = $this->path($key);
        $this->retryRemovals($teacher);
        if (!move_uploaded_file($temporary, $path)) {
            throw new RuntimeException('Unable to store the private teacher document.');
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
        $body = @file_get_contents($this->path($document['storage_key']), length: self::MAX_BYTES + 1);
        if ($body === false || strlen($body) !== (int) $document['bytes']) {
            if ($this->repository->document($teacher, $id) === null) {
                return null;
            }
            throw new RuntimeException('Unable to read the private teacher document.');
        }

        return new Response($body, 200, [
            'Content-Type' => $document['media_type'],
            'Content-Disposition' => 'attachment; filename="' . $document['display_name'] . '"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
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
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('word/document.xml') !== false
                && $zip->locateName('word/vbaProject.bin', ZipArchive::FL_NOCASE) === false;
        } finally {
            $zip->close();
        }
    }

    private function path(string $key): string
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
        return $directory . DIRECTORY_SEPARATOR . $key;
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
        $path = $this->path($key);
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
