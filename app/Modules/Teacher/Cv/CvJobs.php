<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

use Closure;
use Furoeducare\Core\Database;
use PDO;

final class CvJobs
{
    public function __construct(private readonly Closure $database)
    {
    }

    /** May be called inside the same transaction that creates the document. */
    public static function queue(PDO $db, int $document): void
    {
        $db->prepare(
            'INSERT INTO teacher_cv_extractions (document_id) VALUES (?) ON DUPLICATE KEY UPDATE document_id = document_id'
        )->execute([$document]);
    }

    public function current(int $teacher): ?array
    {
        $query = ($this->database)()->prepare(
            "SELECT d.id AS document_id, d.display_name,
                CASE WHEN j.status = 'ready' AND j.expires_at <= CURRENT_TIMESTAMP(6) THEN 'expired'
                     ELSE COALESCE(j.status, 'new') END AS status,
                CASE WHEN j.status = 'ready' AND j.expires_at > CURRENT_TIMESTAMP(6) THEN j.draft END AS draft,
                j.failure_code
             FROM teacher_documents d LEFT JOIN teacher_cv_extractions j ON j.document_id = d.id
             WHERE d.cv_teacher_id = ?"
        );
        $query->execute([$teacher]);
        return $query->fetch() ?: null;
    }

    public function expire(): void
    {
        $db = ($this->database)();
        $db->exec("UPDATE teacher_cv_extractions SET status = 'failed', failure_code = 'expired', draft = NULL, expires_at = NULL
            WHERE status = 'ready' AND expires_at <= CURRENT_TIMESTAMP(6) LIMIT 100");
        $db->exec("UPDATE teacher_cv_extractions SET status = 'failed', failure_code = 'interrupted'
            WHERE status = 'processing' AND started_at <= CURRENT_TIMESTAMP(6) - INTERVAL 5 MINUTE LIMIT 100");
    }

    public function claim(): ?array
    {
        return Database::transaction(($this->database)(), static function (PDO $db): ?array {
            $job = $db->query(
                "SELECT j.document_id, d.storage_key, d.media_type FROM teacher_cv_extractions j
                 JOIN teacher_documents d ON d.id = j.document_id AND d.cv_teacher_id IS NOT NULL
                 WHERE j.status = 'queued' ORDER BY j.document_id LIMIT 1 FOR UPDATE OF j SKIP LOCKED"
            )->fetch();
            if ($job === false) {
                return null;
            }
            $db->prepare(
                "UPDATE teacher_cv_extractions SET status = 'processing', started_at = CURRENT_TIMESTAMP(6) WHERE document_id = ?"
            )->execute([$job['document_id']]);
            return $job;
        });
    }

    public function ready(int $document, #[\SensitiveParameter] array $draft): void
    {
        ($this->database)()->prepare(
            "UPDATE teacher_cv_extractions j JOIN teacher_documents d ON d.id = j.document_id AND d.cv_teacher_id IS NOT NULL
             SET j.status = 'ready', j.draft = ?, j.expires_at = CURRENT_TIMESTAMP(6) + INTERVAL 7 DAY
             WHERE j.document_id = ? AND j.status = 'processing'"
        )->execute([json_encode(CvDraft::validate($draft), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $document]);
    }

    public function fail(int $document, string $code): void
    {
        if (!in_array($code, ['scanned_pdf', 'no_text', 'unreadable', 'limit_exceeded'], true)) {
            $code = 'unreadable';
        }
        ($this->database)()->prepare(
            "UPDATE teacher_cv_extractions SET status = 'failed', failure_code = ? WHERE document_id = ? AND status = 'processing'"
        )->execute([$code, $document]);
    }
}
