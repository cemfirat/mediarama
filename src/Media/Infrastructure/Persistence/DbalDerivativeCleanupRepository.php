<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Media\Application\DerivativeCleanupJob;
use Mediarama\Media\Application\DerivativeCleanupRepository;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\StorageObjectId;
use Symfony\Component\Uid\Uuid;

final readonly class DbalDerivativeCleanupRepository implements DerivativeCleanupRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function stageSuperseded(
        DateTimeImmutable $cutoff,
        int $keepNewestVersions,
        int $limitVersions,
    ): int {
        if ($keepNewestVersions < 1 || $limitVersions < 1) {
            throw new \InvalidArgumentException('Derivative cleanup retention values must be positive.');
        }

        $moved = $this->connection->fetchOne(
            <<<'SQL'
WITH generations AS (
    SELECT
        media_id,
        kind,
        processing_version,
        MIN(created_at) AS generated_at
    FROM media_derivatives
    GROUP BY media_id, kind, processing_version
),
ranked AS (
    SELECT
        media_id,
        kind,
        processing_version,
        generated_at,
        LEAD(generated_at) OVER (
            PARTITION BY media_id, kind
            ORDER BY processing_version ASC
        ) AS superseded_at,
        ROW_NUMBER() OVER (
            PARTITION BY media_id, kind
            ORDER BY processing_version DESC
        ) AS newest_rank
    FROM generations
),
eligible_versions AS (
    SELECT media_id, kind, processing_version
    FROM ranked
    WHERE newest_rank > :keep
      AND superseded_at IS NOT NULL
      AND superseded_at <= :cutoff
    ORDER BY superseded_at ASC, media_id ASC, kind ASC, processing_version ASC
    LIMIT :limit
),
moved AS (
    DELETE FROM media_derivatives d
    USING eligible_versions e
    WHERE d.media_id = e.media_id
      AND d.kind = e.kind
      AND d.processing_version = e.processing_version
    RETURNING
        d.media_id,
        d.kind,
        d.profile,
        d.processing_version,
        d.storage_disk,
        d.storage_key
),
queued AS (
    INSERT INTO derivative_cleanup_jobs (
        storage_disk,
        storage_key,
        reason,
        media_id,
        kind,
        profile,
        processing_version,
        created_at,
        updated_at
    )
    SELECT
        storage_disk,
        storage_key,
        'superseded_version',
        media_id,
        kind,
        profile,
        processing_version,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    FROM moved
    ON CONFLICT (storage_disk, storage_key) DO UPDATE SET
        updated_at = EXCLUDED.updated_at
    RETURNING id
)
SELECT COUNT(*) FROM moved
SQL,
            [
                'keep' => $keepNewestVersions,
                'cutoff' => $cutoff->format(DATE_ATOM),
                'limit' => $limitVersions,
            ],
            [
                'keep' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ],
        );

        return (int) $moved;
    }

    public function pending(int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Derivative cleanup limit must be positive.');
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    id,
    storage_disk,
    storage_key,
    reason,
    media_id,
    kind,
    profile,
    processing_version,
    created_at
FROM derivative_cleanup_jobs
ORDER BY created_at ASC, id ASC
LIMIT :limit
SQL,
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): DerivativeCleanupJob => new DerivativeCleanupJob(
                (int) $row['id'],
                new StorageObjectId((string) $row['storage_disk'], (string) $row['storage_key']),
                (string) $row['reason'],
                $row['media_id'] !== null ? Uuid::fromString((string) $row['media_id']) : null,
                $row['kind'] !== null ? (string) $row['kind'] : null,
                $row['profile'] !== null ? (string) $row['profile'] : null,
                $row['processing_version'] !== null ? (int) $row['processing_version'] : null,
                new DateTimeImmutable((string) $row['created_at']),
            ),
            $rows,
        );
    }

    public function complete(int $jobId): void
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('Derivative cleanup job ID must be positive.');
        }

        $this->connection->delete('derivative_cleanup_jobs', ['id' => $jobId]);
    }

    public function isReferenced(StorageObjectId $storage): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (
                SELECT 1
                FROM media_derivatives
                WHERE storage_disk = :disk AND storage_key = :key
            )',
            [
                'disk' => $storage->disk,
                'key' => $storage->key,
            ],
        );
    }

    public function enqueueOrphan(MediaDerivative $derivative): void
    {
        if (
            $derivative->storage->disk !== 'media'
            || !str_starts_with($derivative->storage->key, 'derivatives/')
        ) {
            throw new \InvalidArgumentException('Only Mediarama derivative objects may be queued for cleanup.');
        }

        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO derivative_cleanup_jobs (
    storage_disk,
    storage_key,
    reason,
    media_id,
    kind,
    profile,
    processing_version,
    created_at,
    updated_at
) VALUES (
    :disk,
    :key,
    'failed_generation_orphan',
    :media,
    :kind,
    :profile,
    :version,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
)
ON CONFLICT (storage_disk, storage_key) DO UPDATE SET
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'disk' => $derivative->storage->disk,
                'key' => $derivative->storage->key,
                'media' => $derivative->mediaId->toRfc4122(),
                'kind' => $derivative->kind,
                'profile' => $derivative->profile,
                'version' => $derivative->processingVersion,
            ],
        );
    }
}
