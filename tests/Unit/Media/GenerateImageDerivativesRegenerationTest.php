<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use DateTimeImmutable;
use Mediarama\Media\Application\DerivativeCleanupRepository;
use Mediarama\Media\Application\DerivativeCleanupSummary;
use Mediarama\Media\Application\GenerateImageDerivatives;
use Mediarama\Media\Application\StorageCleanupJob;
use Mediarama\Media\Application\ImageDerivativeGenerator;
use Mediarama\Media\Application\ImageDerivativeProfile;
use Mediarama\Media\Application\MediaDerivativeRepository;
use Mediarama\Media\Application\MediaDerivativeRegenerationLock;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class GenerateImageDerivativesRegenerationTest extends TestCase
{
    public function testRegenerationCreatesOneAtomicNextVersionForAllProfiles(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            /** @var list<MediaDerivative> */
            public array $savedAll = [];

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                $this->savedAll = $derivatives;
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 4;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            /** @var list<int> */
            public array $versions = [];

            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $this->versions[] = $processingVersion;
                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId(
                        'media',
                        sprintf(
                            'derivatives/%s/v%d/%s.webp',
                            $media->id->toRfc4122(),
                            $processingVersion,
                            $profile->name,
                        ),
                    ),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $storage = $this->trackingStorage();

        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $storage,
            $this->immediateLock(),
            [
                new ImageDerivativeProfile('thumbnail', 480, 480),
                new ImageDerivativeProfile('preview', 1600, 1600),
                new ImageDerivativeProfile('large', 2560, 2560),
            ],
            1,
        );

        $version = $service->regenerate($asset);

        self::assertSame(5, $version);
        self::assertSame([5, 5, 5], $generator->versions);
        self::assertCount(3, $repository->savedAll);
        self::assertSame(
            ['thumbnail', 'preview', 'large'],
            array_map(static fn (MediaDerivative $d): string => $d->profile, $repository->savedAll),
        );
        self::assertSame(
            [5, 5, 5],
            array_map(static fn (MediaDerivative $d): int => $d->processingVersion, $repository->savedAll),
        );
        self::assertSame([], $storage->deleted);
    }

    public function testConfiguredVersionIsTheRegenerationFloor(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            /** @var list<MediaDerivative> */
            public array $savedAll = [];

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                $this->savedAll = $derivatives;
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 0;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId('media', 'derivatives/test/v'.$processingVersion.'/'.$profile->name.'.webp'),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $this->trackingStorage(),
            $this->immediateLock(),
            [new ImageDerivativeProfile('thumbnail', 480, 480)],
            3,
        );

        self::assertSame(3, $service->regenerate($asset));
        self::assertSame(3, $repository->savedAll[0]->processingVersion);
    }

    public function testFailedRegenerationCleansGeneratedArtifactsAndPublishesNothing(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            public bool $saveAllCalled = false;

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                $this->saveAllCalled = true;
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 2;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            private int $calls = 0;

            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                ++$this->calls;

                if ($this->calls === 2) {
                    throw new \RuntimeException('Synthetic second-profile failure.');
                }

                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId(
                        'media',
                        sprintf(
                            'derivatives/%s/v%d/%s.webp',
                            $media->id->toRfc4122(),
                            $processingVersion,
                            $profile->name,
                        ),
                    ),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $storage = $this->trackingStorage();

        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $storage,
            $this->immediateLock(),
            [
                new ImageDerivativeProfile('thumbnail', 480, 480),
                new ImageDerivativeProfile('preview', 1600, 1600),
            ],
            1,
        );

        try {
            $service->regenerate($asset);
            self::fail('Expected second profile generation to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic second-profile failure.', $error->getMessage());
        }

        self::assertFalse($repository->saveAllCalled);
        self::assertCount(1, $storage->deleted);
        self::assertStringContainsString('/v3/thumbnail.webp', $storage->deleted[0]->key);
    }

    public function testPersistenceFailureCleansEveryGeneratedArtifact(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                throw new \RuntimeException('Synthetic batch persistence failure.');
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 7;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId(
                        'media',
                        sprintf(
                            'derivatives/%s/v%d/%s.webp',
                            $media->id->toRfc4122(),
                            $processingVersion,
                            $profile->name,
                        ),
                    ),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $storage = $this->trackingStorage();
        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $storage,
            $this->immediateLock(),
            [
                new ImageDerivativeProfile('thumbnail', 480, 480),
                new ImageDerivativeProfile('preview', 1600, 1600),
            ],
            1,
        );

        try {
            $service->regenerate($asset);
            self::fail('Expected batch persistence to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic batch persistence failure.', $error->getMessage());
        }

        self::assertCount(2, $storage->deleted);
        self::assertStringContainsString('/v8/thumbnail.webp', $storage->deleted[0]->key);
        self::assertStringContainsString('/v8/preview.webp', $storage->deleted[1]->key);
    }

    public function testFailedStorageDeleteLeavesRetryableOrphanCleanupJob(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                throw new \RuntimeException('Synthetic persistence failure.');
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 1;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId(
                        'media',
                        sprintf(
                            'derivatives/%s/v%d/%s.webp',
                            $media->id->toRfc4122(),
                            $processingVersion,
                            $profile->name,
                        ),
                    ),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $storage = new class implements MediaStorage {
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
                return true;
            }

            public function stat(StorageObjectId $id): StoredObject
            {
                throw new \LogicException('Not used by this test.');
            }

            public function delete(StorageObjectId $id): void
            {
                throw new \RuntimeException('Synthetic storage delete failure.');
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

        $cleanup = new class implements DerivativeCleanupRepository {
            /** @var list<StorageCleanupJob> */
            public array $queued = [];
            /** @var list<string> */
            public array $completed = [];

            public function enqueueOrphanedDerivative(MediaDerivative $derivative): StorageCleanupJob
            {
                $job = new StorageCleanupJob(Uuid::v7(), $derivative->storage);
                $this->queued[] = $job;

                return $job;
            }

            public function previewSuperseded(
                DateTimeImmutable $cutoff,
                int $keepVersions,
                int $generationLimit,
            ): DerivativeCleanupSummary {
                return DerivativeCleanupSummary::empty();
            }

            public function enqueueSuperseded(
                DateTimeImmutable $cutoff,
                int $keepVersions,
                int $generationLimit,
            ): DerivativeCleanupSummary {
                return DerivativeCleanupSummary::empty();
            }

            public function claimStorageJobs(
                int $limit,
                DateTimeImmutable $claimedAt,
                DateTimeImmutable $staleBefore,
            ): array {
                return [];
            }

            public function completeStorageJob(Uuid $id): void
            {
                $this->completed[] = $id->toRfc4122();
            }

            public function failStorageJob(Uuid $id, string $error): void
            {
            }

            public function pendingStorageJobCount(): int
            {
                return count($this->queued) - count($this->completed);
            }
        };

        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $storage,
            $this->immediateLock(),
            [new ImageDerivativeProfile('thumbnail', 480, 480)],
            1,
            $cleanup,
        );

        try {
            $service->regenerate($asset);
            self::fail('Expected persistence failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic persistence failure.', $error->getMessage());
        }

        self::assertCount(1, $cleanup->queued);
        self::assertSame([], $cleanup->completed);
        self::assertStringContainsString('/v2/thumbnail.webp', $cleanup->queued[0]->storage->key);
    }

    private function imageAsset(): MediaAsset
    {
        return MediaAsset::create(
            null,
            new StorageObjectId('media', 'originals/test.jpg'),
            'test.jpg',
            'image/jpeg',
            MediaType::Image,
            123,
            str_repeat('a', 64),
        );
    }


    private function immediateLock(): MediaDerivativeRegenerationLock
    {
        return new class implements MediaDerivativeRegenerationLock {
            public function synchronized(Uuid $mediaId, string $kind, callable $operation): mixed
            {
                return $operation();
            }
        };
    }

    private function trackingStorage(): MediaStorage
    {
        return new class implements MediaStorage {
            /** @var list<StorageObjectId> */
            public array $deleted = [];

            public function write(StorageObjectId $id, $stream, ?string $contentType = null): StoredObject
            {
                throw new \LogicException('Storage write is owned by the derivative generator in this unit test.');
            }

            public function read(StorageObjectId $id)
            {
                throw new \LogicException('Storage read is not used in this unit test.');
            }

            public function exists(StorageObjectId $id): bool
            {
                return false;
            }

            public function stat(StorageObjectId $id): StoredObject
            {
                throw new \LogicException('Storage stat is not used in this unit test.');
            }

            public function delete(StorageObjectId $id): void
            {
                $this->deleted[] = $id;
            }

            public function promote(StorageObjectId $temporary, StorageObjectId $permanent): StoredObject
            {
                throw new \LogicException('Storage promote is not used in this unit test.');
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
