<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Library;

use Furoeducare\Modules\Admin\StaffAccess;

use DomainException;
use Furoeducare\Core\Response;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;
use RuntimeException;

final class LibraryService
{
    public function __construct(private readonly LibraryRepository $library, private readonly LibraryFiles $files)
    {
    }

    public function grantExpiry(array $resource, ?array $viewer): ?string
    {
        if ($viewer === null || $resource['access'] !== 'restricted') {
            return null;
        }
        try {
            return $this->library->grantExpiry($resource, (int) $viewer['id']);
        } catch (DomainException) {
            return null;
        }
    }

    public function canDownload(array $resource, ?array $viewer): bool
    {
        return $resource['access'] === 'free'
            || ($resource['access'] === 'restricted' && $this->grantExpiry($resource, $viewer) !== null);
    }

    public function download(string $reference, ?array $viewer, bool $head = false): Response
    {
        return $this->library->readResource($reference, function (array $row, PDO $db) use ($viewer, $head): Response {
            self::requirePublished($row);
            if ($row['access'] === 'future_paid') {
                throw new DomainException('Paid access is reserved for a future release.', 403);
            }
            if ($row['access'] === 'restricted'
                && ($viewer === null || $this->library->grantExpiry($row, (int) $viewer['id'], $db) === null)) {
                throw new DomainException('This resource requires an active access grant for your account.', 403);
            }
            $body = $this->read($row, 'material');
            if (!$head) {
                if ($viewer !== null) {
                    LibraryRepository::requireAccount($db, (int) $viewer['id'], false, true);
                }
                LibraryRepository::query($db, 'INSERT INTO library_downloads (resource_id, resource_revision, user_id, title_snapshot) VALUES (?, ?, ?, ?)',
                    [$row['id'], $row['revision'], $viewer['id'] ?? null, $row['title']]);
            }
            return self::fileResponse($row, $body, 'material', $head);
        });
    }

    public function cover(string $reference, bool $head = false): Response
    {
        return $this->library->readResource($reference, function (array $row) use ($head): Response {
            self::requirePublished($row);
            return self::fileResponse($row, $this->read($row, 'cover'), 'cover', $head);
        });
    }

    public function preview(int $actor, int $id, bool $head = false): Response
    {
        return $this->library->readResource($id, function (array $row, PDO $db) use ($actor, $head): Response {
            LibraryRepository::requireAccount($db, $actor, true, true);
            StaffAccess::requireIn($db, $actor, 'library.view', true);
            if ($row['status'] === 'removed') {
                throw new DomainException('This resource is unavailable.', 404);
            }
            $body = $this->read($row, 'material');
            if (!$head) {
                VerificationEvents::audit($db, $actor, 'library.previewed', ['resource_id' => $row['id'], 'revision' => $row['revision']]);
            }
            return self::fileResponse($row, $body, 'material', $head);
        });
    }

    private function read(array $row, string $kind): string
    {
        if ($row[$kind . '_key'] === null) {
            throw new DomainException('This file is unavailable.', 404);
        }
        try {
            return $this->files->read($row[$kind . '_key'], (int) $row[$kind . '_bytes'], $row[$kind . '_sha256'], $row[$kind . '_media_type']);
        } catch (RuntimeException) {
            error_log('Library file unavailable or integrity validation failed.');
            throw new DomainException('This file is temporarily unavailable. Please contact Furoeducare.', 404);
        }
    }

    private static function requirePublished(array $row): void
    {
        if (!LibraryRepository::visible($row)) {
            throw new DomainException('This resource is unavailable.', 404);
        }
    }

    private static function fileResponse(array $row, string $body, string $kind, bool $head): Response
    {
        $headers = [
            'Content-Type' => $row[$kind . '_media_type'], 'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
        if ($kind === 'material') {
            $headers['Content-Disposition'] = 'attachment; filename="library-' . $row['reference'] . '.' . $row['material_extension'] . '"';
        }
        return new Response($head ? '' : $body, 200, $headers);
    }
}
