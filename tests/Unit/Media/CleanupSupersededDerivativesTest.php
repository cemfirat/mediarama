<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use DateTimeImmutable;
use Mediarama\Media\Application\CleanupSupersededDerivatives;
use Mediarama\Media\Application\DerivativeCleanupRepository;
use Mediarama\Media\Application\DerivativeCleanupSummary;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\StorageCleanupJob;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Domain\StorageObjectId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CleanupSupersededDerivativesTest extends TestCase
{
    public function testPreviewDoesNotClaimOrDeleteStorage(): void
    {
        $summary = new DerivativeCleanupSummary(2, 6, 600);
        $repository = $this->repository($summary, []);
        $storage = $this->storage([]);

        $service = new CleanupSupersededDerivatives($repository, $storage);
        $actual = $service->preview(new DateTimeImmutable('-400 days'), 3, 100);

        self::assertSame($summary, $actual);
        self::assertSame(0, $repository->enqueueCalls);
        self::assertSame([], $storage->deleted);
    }

    public function testRunCompletesMissingObjectAndRequeuesFailedDelete(): void
    {
        $ok = new StorageCleanupJob(
            Uuid::v7(),
            new StorageObjectId('media', 'derivatives/a/v1/thumbnail.webp'),
        );
        $missing = new StorageCleanupJob(
            Uuid::v7(),
            new StorageObjectId('media', 'derivatives/a/v1/preview.webp'),
        );
        $failed = new StorageCleanupJob(
            Uuid::v7(),
            new StorageObjectId('media', 'derivatives/a/v1/large.webp'),
        );

        $summary = new DerivativeCleanupSummary(1, 3, 300);
        $repository = $this->repository($summary, [$ok, $missing, $failed]);
        $repository->pending = 1;

        $storage = $this->storage([
            $ok->storage->key => 'present',
            $failed->storage->key => 'fail',
        ]);

        $service = new CleanupSupersededDerivatives($repository, $storage);
        $result = $service->run(new DateTimeImmutable('-400 days'), 3, 100, 100);

        self::assertSame(2, $result->storageDeleted);
        self::assertSame(1, $result->storageFailed);
        self::assertSame(1, $result->storagePending);
        self::assertSame([$ok->id->toRfc4122(), $missing->id->toRfc4122()], $repository->completed);
        self::assertSame([$failed->id->toRfc4122()], $repository->failed);
        self::assertSame([$ok->storage->key], $storage->deleted);
    }

    /**
     * @param list<StorageCleanupJob> $jobs
     */
    private function repository(
        DerivativeCleanupSummary $summary,
        array $jobs,
    ): DerivativeCleanupRepository {
        return new class($summary, $jobs) implements DerivativeCleanupRepository {
            public int $enqueueCalls = 0;
            public int $pending = 0;
            /** @var list<string> */
            public array $completed = [];
            /** @var list<string> */
            public array $failed = [];

            /** @param list<StorageCleanupJob> $jobs */
            public function __construct(
                private readonly DerivativeCleanupSummary $summary,
                private readonly array $jobs,
            ) {
            }

            public function enqueueOrphanedDerivative(\Mediarama\Media\Domain\MediaDerivative $derivative): StorageCleanupJob
            {
                return new StorageCleanupJob(Uuid::v7(), $derivative->storage);
            }

            public function previewSuperseded(
                DateTimeImmutable $cutoff,
                int $keepVersions,
                int $generationLimit,
            ): DerivativeCleanupSummary {
                return $this->summary;
            }

            public function enqueueSuperseded(
                DateTimeImmutable $cutoff,
                int $keepVersions,
                int $generationLimit,
            ): DerivativeCleanupSummary {
                ++$this->enqueueCalls;

                return $this->summary;
            }

            public function claimStorageJobs(
                int $limit,
                DateTimeImmutable $claimedAt,
                DateTimeImmutable $staleBefore,
            ): array {
                return array_slice($this->jobs, 0, $limit);
            }

            public function completeStorageJob(Uuid $id): void
            {
                $this->completed[] = $id->toRfc4122();
            }

            public function failStorageJob(Uuid $id, string $error): void
            {
                if ($error !== 'storage_delete_failed') {
                    throw new \RuntimeException('Unexpected storage cleanup failure code.');
                }

                $this->failed[] = $id->toRfc4122();
            }

            public function pendingStorageJobCount(): int
            {
                return $this->pending;
            }
        };
    }

    /**
     * @param array<string, 'present'|'fail'> $objects
     */
    private function storage(array $objects): MediaStorage
    {
        return new class($objects) implements MediaStorage {
            /** @var list<string> */
            public array $deleted = [];

            /** @param array<string, 'present'|'fail'> $objects */
            public function __construct(private array $objects)
            {
            }

            public function write(StorageObjectId $id, $stream, ?string $contentType = null): StoredObject
            {
                throw new \LogicException('Not used by this test.');
            }

            public function read(StorageObjectId $id)
            {
                throw new \LogicException('Not used by this test.');
            }

            public function exists(StorageObjectId $id): bool
            {
                return array_key_exists($id->key, $this->objects);
            }

            public function stat(StorageObjectId $id): StoredObject
            {
                throw new \LogicException('Not used by this test.');
            }

            public function delete(StorageObjectId $id): void
            {
                if (($this->objects[$id->key] ?? null) === 'fail') {
                    throw new \RuntimeException('Synthetic storage failure.');
                }

                unset($this->objects[$id->key]);
                $this->deleted[] = $id->key;
            }

            public function promote(StorageObjectId $temporary, StorageObjectId $permanent): StoredObject
            {
                throw new \LogicException('Not used by this test.');
            }

            public function publicUrl(StorageObjectId $id): ?string
            {
                return null;
            }

            public function temporaryUrl(StorageObjectId $id, DateTimeImmutable $expiresAt): ?string
            {
                return null;
            }
        };
    }
}
