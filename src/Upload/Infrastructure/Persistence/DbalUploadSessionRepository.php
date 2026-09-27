<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Upload\Application\UploadProblem;
use Mediarama\Upload\Application\UploadSessionRepository;
use Mediarama\Upload\Domain\UploadFailure;
use Mediarama\Upload\Domain\UploadFailureStage;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Uid\Uuid;

final readonly class DbalUploadSessionRepository implements UploadSessionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(UploadSession $session): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO upload_sessions (
    id, user_id, target_collection_id, original_filename, expected_size,
    expected_mime, temporary_storage_key, status, expires_at, created_at, updated_at,
    last_failure_code, last_failure_stage, last_failure_retryable, last_failed_at
) VALUES (
    :id, :user_id, :target_collection_id, :original_filename, :expected_size,
    :expected_mime, :temporary_storage_key, :status, :expires_at, :created_at, :updated_at,
    :last_failure_code, :last_failure_stage, :last_failure_retryable, :last_failed_at
)
ON CONFLICT (id) DO UPDATE SET
    target_collection_id = EXCLUDED.target_collection_id,
    expected_mime = EXCLUDED.expected_mime,
    status = EXCLUDED.status,
    expires_at = EXCLUDED.expires_at,
    updated_at = EXCLUDED.updated_at,
    last_failure_code = EXCLUDED.last_failure_code,
    last_failure_stage = EXCLUDED.last_failure_stage,
    last_failure_retryable = EXCLUDED.last_failure_retryable,
    last_failed_at = EXCLUDED.last_failed_at
SQL,
            [
                'id' => $session->id->toRfc4122(),
                'user_id' => $session->userId->toRfc4122(),
                'target_collection_id' => $session->targetCollectionId?->toRfc4122(),
                'original_filename' => $session->originalFilename,
                'expected_size' => $session->expectedSize,
                'expected_mime' => $session->expectedMime,
                'temporary_storage_key' => $session->temporaryStorageKey,
                'status' => $session->status->value,
                'expires_at' => $session->expiresAt->format(DATE_ATOM),
                'created_at' => $session->createdAt->format(DATE_ATOM),
                'updated_at' => $session->updatedAt->format(DATE_ATOM),
                'last_failure_code' => $session->lastFailure?->code,
                'last_failure_stage' => $session->lastFailure?->stage->value,
                'last_failure_retryable' => $session->lastFailure?->retryable,
                'last_failed_at' => $session->lastFailure?->failedAt->format(DATE_ATOM),
            ],
            ['last_failure_retryable' => ParameterType::BOOLEAN],
        );
    }

    public function delete(UploadSession $session): void
    {
        $this->connection->delete(
            'upload_sessions',
            ['id' => $session->id->toRfc4122()],
        );
    }

    public function get(Uuid $id): UploadSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM upload_sessions WHERE id = :id',
            ['id' => $id->toRfc4122()],
        );

        if ($row === false) {
            throw UploadProblem::request(
                'upload_not_found',
                'Upload session not found.',
            );
        }

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
    }
}
