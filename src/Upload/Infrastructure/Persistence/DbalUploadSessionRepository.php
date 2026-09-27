<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Upload\Application\UploadSessionRepository;
use Mediarama\Upload\Domain\UploadProblem;
use Mediarama\Upload\Domain\UploadSession;
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
                'last_failure_code' => $session->lastFailureCode?->value,
                'last_failure_stage' => $session->lastFailureStage?->value,
                'last_failure_retryable' => $session->lastFailureRetryable,
                'last_failed_at' => $session->lastFailedAt?->format(DATE_ATOM),
            ],
            $session->lastFailureRetryable !== null
                ? ['last_failure_retryable' => ParameterType::BOOLEAN]
                : [],
        );
    }

    public function get(Uuid $id): UploadSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM upload_sessions WHERE id = :id',
            ['id' => $id->toRfc4122()],
        );

        if ($row === false) {
            throw UploadProblem::sessionNotFound();
        }

        return DbalUploadSessionMapper::fromRow($row);
    }
}
