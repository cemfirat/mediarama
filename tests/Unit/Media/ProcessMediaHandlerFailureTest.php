<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use Mediarama\Media\Application\ApplyInspectedMetadata;
use Mediarama\Media\Application\GenerateImageDerivatives;
use Mediarama\Media\Application\ImageDerivativeGenerator;
use Mediarama\Media\Application\ImageDerivativeProfile;
use Mediarama\Media\Application\InspectImageGeometry;
use Mediarama\Media\Application\InspectMediaMetadata;
use Mediarama\Media\Application\InspectedMetadata;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MediaDerivativeRepository;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Application\MediaMetadataInspector;
use Mediarama\Media\Application\ProcessMedia;
use Mediarama\Media\Application\ProcessMediaHandler;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\ProcessingState;
use Mediarama\Media\Domain\StorageObjectId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProcessMediaHandlerFailureTest extends TestCase
{
    public function testGeometryFailureMarksImageFailedBeforeDerivativeGeneration(): void
    {
        $asset = MediaAsset::create(
            null,
            new StorageObjectId('media', 'originals/oversized.jpg'),
            'oversized.jpg',
            'image/jpeg',
            MediaType::Image,
            1,
            str_repeat('a', 64),
        );

        $media = new class($asset) implements MediaAssetRepository {
            public function __construct(private MediaAsset $asset)
            {
            }

            public function save(MediaAsset $media): void
            {
                $this->asset = $media;
            }

            public function get(Uuid $id): MediaAsset
            {
                return $this->asset;
            }
        };

        $metadataInspector = new class implements MediaMetadataInspector {
            public function inspect(StorageObjectId $original): InspectedMetadata
            {
                return new InspectedMetadata([]);
            }
        };

        $metadata = new InspectMediaMetadata(
            $media,
            $metadataInspector,
            new ApplyInspectedMetadata(),
        );

        $derivatives = new class implements MediaDerivativeRepository {
            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Derivative generation must not run after geometry failure.');
            }

            public function saveAll(array $derivatives): void
            {
                throw new \LogicException('Derivative generation must not run after geometry failure.');
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
                throw new \LogicException('Derivative generation must not run after geometry failure.');
            }
        };


        $storage = new class implements MediaStorage {
            public function write(StorageObjectId $id, $stream, ?string $contentType = null): StoredObject
            {
                throw new \LogicException('Storage must not be used after geometry failure.');
            }

            public function read(StorageObjectId $id)
            {
                throw new \LogicException('Storage must not be used after geometry failure.');
            }

            public function exists(StorageObjectId $id): bool
            {
                return false;
            }

            public function stat(StorageObjectId $id): StoredObject
            {
                throw new \LogicException('Storage must not be used after geometry failure.');
            }

            public function delete(StorageObjectId $id): void
            {
                throw new \LogicException('Storage must not be used after geometry failure.');
            }

            public function promote(StorageObjectId $temporary, StorageObjectId $permanent): StoredObject
            {
                throw new \LogicException('Storage must not be used after geometry failure.');
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

        $images = new GenerateImageDerivatives(
            $derivatives,
            $generator,
            $storage,
            [new ImageDerivativeProfile('test', 64, 64)],
            1,
        );

        $geometry = new class implements InspectImageGeometry {
            public function __invoke(MediaAsset $media): array
            {
                throw new \RuntimeException('ImageMagick identify failed: width limit exceeded.');
            }
        };

        $handler = new ProcessMediaHandler(
            $metadata,
            $images,
            $geometry,
            $media,
        );

        try {
            $handler(new ProcessMedia($asset->id->toRfc4122()));
            self::fail('Expected geometry inspection to fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('width limit exceeded', $error->getMessage());
        }

        self::assertSame(ProcessingState::Failed, $asset->processingState);
    }
}
