<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Media\Application\DerivativeCleanupRepository;
use Mediarama\Media\Application\DerivativeCleanupSummary;
use Mediarama\Media\Application\StorageCleanupJob;
use Mediarama\Media\Domain\StorageObjectId;
use Symfony\Component\Uid\Uuid;

final readonly class DbalDerivativeCleanupRepository implements DerivativeCleanupRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function previewSuperseded(
        DateTimeImmutable $cutoff,
        int $keepVersions,
        int $generationLimit,
    ): DerivativeCleanupSummary {
        return $this->summarize($this->candidateRows(
            $cutoff,
            $keepVersions,
            $generationLimit,
            false,
        ));
    }

    public function enqueueSuperseded(
        DateTimeImmutable $cutoff,
        int $keepVersions,
        int $generationLimit,
    ): DerivativeCleanupSummary {
        return $this->connection->transactional(function () use (
            $cutoff,
            $keepVersions,
            $generationLimit,
        ): DerivativeCleanupSummary {
            $rows = $this->candidateRows(
                $cutoff,
                $keepVersions,
                $generationLimit,
                true,
            );

            if ($rows === []) {
                return DerivativeCleanupSummary::empty();
            }

            $now = (new DateTimeImmutable())->format(DATE_ATOM);

            foreach ($rows as $row) {
                $mediaId = (string) $row['media_id'];
                $version = (int) $row['processing_version'];
                $storageKey = (string) $row['storage_key'];
                $expectedPrefix = sprintf('derivatives/%s/v%d/', $mediaId, $version);

                if (!str_starts_with($storageKey, $expectedPrefix)) {
                    throw new \LogicException(sprintf(
                        'Refusing to enqueue derivative storage key outside the owned generation prefix for media %s version %d.',
                        $mediaId,
                        $version,
                    ));
                }

                $this->connection->executeStatement(
                    <<<'SQL'
INSERT INTO storage_cleanup_jobs (
    id, storage_disk, storage_key, reason, metadata, status, attempts,
    claimed_at, last_error, created_at, updated_at
) VALUES (
    :id, :disk, :key, :reason, CAST(:metadata AS JSONB), 'pending', 0,
    NULL, NULL, :created, :updated
)
ON CONFLICT (storage_disk, storage_key) DO NOTHING
SQL,
                    [
                        'id' => Uuid::v7()->toRfc4122(),
                        'disk' => (string) $row['storage_disk'],
                        'key' => $storageKey,
                        'reason' => 'superseded_derivative',
                        'metadata' => json_encode([
                            'media_id' => $mediaId,
                            'kind' => (string) $row['kind'],
                            'profile' => (string) $row['profile'],
                            'processing_version' => $version,
                            'byte_size' => (int) $row['byte_size'],
                        ], JSON_THROW_ON_ERROR),
                        'created' => $now,
                        'updated' => $now,
                    ],
                );

                $deleted = $this->connection->executeStatement(
                    'DELETE FROM media_derivatives WHERE id = :id',
                    ['id' => (string) $row['id']],
                );

                if ($deleted !== 1) {
                    throw new \RuntimeException('Derivative cleanup lost its locked database row.');
                }
            }

            return $this->summarize($rows);
        });
    }

    public function claimStorageJobs(
        int $limit,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $staleBefore,
    ): array {
        $rows = $this->connection->transactional(function () use (
            $limit,
            $claimedAt,
            $staleBefore,
        ): array {
            return $this->connection->fetchAllAssociative(
                <<<'SQL'
WITH candidate AS (
    SELECT id
    FROM storage_cleanup_jobs
    WHERE status = 'pending'
       OR (status = 'processing' AND claimed_at < :stale_before)
    ORDER BY created_at ASC, id ASC
    LIMIT :limit
    FOR UPDATE SKIP LOCKED
)
UPDATE storage_cleanup_jobs job
SET status = 'processing',
    attempts = job.attempts + 1,
    claimed_at = :claimed_at,
    last_error = NULL,
    updated_at = :claimed_at
FROM candidate
WHERE job.id = candidate.id
RETURNING job.id, job.storage_disk, job.storage_key
SQL,
                [
                    'limit' => $limit,
                    'claimed_at' => $claimedAt->format(DATE_ATOM),
                    'stale_before' => $staleBefore->format(DATE_ATOM),
                ],
                [
                    'limit' => ParameterType::INTEGER,
                ],
            );
        });

        return array_map(
            static fn (array $row): StorageCleanupJob => new StorageCleanupJob(
                Uuid::fromString((string) $row['id']),
                new StorageObjectId(
                    (string) $row['storage_disk'],
                    (string) $row['storage_key'],
                ),
            ),
            $rows,
        );
    }

    public function completeStorageJob(Uuid $id): void
    {
        $this->connection->executeStatement(
            'DELETE FROM storage_cleanup_jobs WHERE id = :id',
            ['id' => $id->toRfc4122()],
        );
    }

    public function failStorageJob(Uuid $id, string $error): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
UPDATE storage_cleanup_jobs
SET status = 'pending',
    claimed_at = NULL,
    last_error = :error,
    updated_at = :updated
WHERE id = :id
SQL,
            [
                'id' => $id->toRfc4122(),
                'error' => mb_substr($error, 0, 120),
                'updated' => (new DateTimeImmutable())->format(DATE_ATOM),
            ],
        );
    }

    public function pendingStorageJobCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM storage_cleanup_jobs WHERE status IN ('pending', 'processing')",
        );
    }

    /**
     * @return list<array{
     *     id:string,
     *     media_id:string,
     *     kind:string,
     *     profile:string,
     *     processing_version:int|string,
     *     storage_disk:string,
     *     storage_key:string,
     *     byte_size:int|string
     * }>
     */
    private function candidateRows(
        DateTimeImmutable $cutoff,
        int $keepVersions,
        int $generationLimit,
        bool $lock,
    ): array {
        $sql = <<<'SQL'
WITH ranked_generations AS (
    SELECT
        media_id,
        kind,
        processing_version,
        MAX(GREATEST(created_at, updated_at)) AS generation_touched_at,
        DENSE_RANK() OVER (
            PARTITION BY media_id, kind
            ORDER BY processing_version DESC
        ) AS generation_rank
    FROM media_derivatives
    GROUP BY media_id, kind, processing_version
),
candidate_generations AS (
    SELECT media_id, kind, processing_version
    FROM ranked_generations
    WHERE generation_rank > :keep_versions
      AND generation_touched_at < :cutoff
    ORDER BY generation_touched_at ASC, media_id ASC, kind ASC, processing_version ASC
    LIMIT :generation_limit
)
SELECT
    derivative.id,
    derivative.media_id,
    derivative.kind,
    derivative.profile,
    derivative.processing_version,
    derivative.storage_disk,
    derivative.storage_key,
    derivative.byte_size
FROM media_derivatives derivative
INNER JOIN candidate_generations candidate
    ON candidate.media_id = derivative.media_id
   AND candidate.kind = derivative.kind
   AND candidate.processing_version = derivative.processing_version
ORDER BY
    derivative.media_id ASC,
    derivative.kind ASC,
    derivative.processing_version ASC,
    derivative.profile ASC
SQL;

        if ($lock) {
            $sql .= "
FOR UPDATE OF derivative";
        }

        /** @var list<array{id:string,media_id:string,kind:string,profile:string,processing_version:int|string,storage_disk:string,storage_key:string,byte_size:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'keep_versions' => $keepVersions,
                'cutoff' => $cutoff->format(DATE_ATOM),
                'generation_limit' => $generationLimit,
            ],
            [
                'keep_versions' => ParameterType::INTEGER,
                'generation_limit' => ParameterType::INTEGER,
            ],
        );

        return $rows;
    }

    /**
     * @param list<array{
     *     id:string,
     *     media_id:string,
     *     kind:string,
     *     profile:string,
     *     processing_version:int|string,
     *     storage_disk:string,
     *     storage_key:string,
     *     byte_size:int|string
     * }> $rows
     */
    private function summarize(array $rows): DerivativeCleanupSummary
    {
        if ($rows === []) {
            return DerivativeCleanupSummary::empty();
        }

        $generations = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $generations[sprintf(
                '%s|%s|%d',
                (string) $row['media_id'],
                (string) $row['kind'],
                (int) $row['processing_version'],
            )] = true;
            $bytes += (int) $row['byte_size'];
        }

        return new DerivativeCleanupSummary(
            count($generations),
            count($rows),
            $bytes,
        );
    }
}
