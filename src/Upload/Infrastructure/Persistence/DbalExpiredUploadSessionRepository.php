<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Upload\Application\ExpiredUploadSessionRepository;
use Mediarama\Upload\Domain\UploadFailure;
use Mediarama\Upload\Domain\UploadFailureStage;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Uid\Uuid;

final readonly class DbalExpiredUploadSessionRepository implements ExpiredUploadSessionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findExpired(DateTimeImmutable $now, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT *
FROM upload_sessions
WHERE expires_at < :now
  AND status IN ('created', 'uploading', 'uploaded', 'failed')
ORDER BY expires_at ASC
LIMIT :limit
SQL,
            ['now' => $now->format(DATE_ATOM), 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static function (array $row): UploadSession {
            $failure = null;
            if ($row['last_failure_code'] !== null) {
                $failure = new UploadFailure(
                    (string) $row['last_failure_code'],
                    UploadFailureStage::from((string) $row['last_failure_stage']),
                    in_array(strtolower((string) $row['last_failure_retryable']), ['1', 't', 'true'], true),
                    new DateTimeImmutable((string) $row['last_failed_at']),
                );
            }

            return UploadSession::reconstitute(
                Uuid::fromString((string) $row['id']),
                Uuid::fromString((string) $row['user_id']),
                $row['target_collection_id'] !== null ? Uuid::fromString((string) $row['target_collection_id']) : null,
                (string) $row['original_filename'],
                (int) $row['expected_size'],
                $row['expected_mime'] !== null ? (string) $row['expected_mime'] : null,
                (string) $row['temporary_storage_key'],
                UploadStatus::from((string) $row['status']),
                new DateTimeImmutable((string) $row['expires_at']),
                new DateTimeImmutable((string) $row['created_at']),
                new DateTimeImmutable((string) $row['updated_at']),
                $failure,
            );
        }, $rows);
    }

    public function delete(UploadSession $session): void
    {
        $this->connection->delete('upload_sessions', ['id' => $session->id->toRfc4122()]);
    }
}
