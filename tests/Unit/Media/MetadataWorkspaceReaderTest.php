<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use DateTimeImmutable;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MetadataWorkspaceReader;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\ModerationState;
use Mediarama\Media\Domain\ProcessingState;
use Mediarama\Media\Domain\StorageObjectId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class MetadataWorkspaceReaderTest extends TestCase
{
    public function testOwnerSeesSeparatedCurrentProvenanceAndCompleteSourceGroups(): void
    {
        $owner = Uuid::fromString('77777777-7777-4777-8777-777777777771');
        $media = $this->media($owner);
        $reader = new MetadataWorkspaceReader($this->repository($media));

        $view = $reader->read($owner, $media->id);

        $current = [];
        foreach ($view->currentFields as $field) {
            $current[$field['key']] = $field;
        }

        self::assertSame('Current title', $current['title']['value']);
        self::assertSame('user', $current['title']['provenance']);
        self::assertTrue($current['latitude']['sensitive']);
        self::assertSame('48.123456', $current['latitude']['value']);

        self::assertArrayHasKey('exif', $view->sourceGroups);
        self::assertArrayHasKey('iptc', $view->sourceGroups);
        self::assertArrayHasKey('xmp', $view->sourceGroups);
        self::assertArrayHasKey('icc', $view->sourceGroups);
        self::assertArrayHasKey('technical', $view->sourceGroups);
        self::assertArrayHasKey('other', $view->sourceGroups);

        $xmp = [];
        foreach ($view->sourceGroups['xmp']['tags'] as $tag) {
            $xmp[$tag['name']] = $tag;
        }
        self::assertSame('Source title', $xmp['Title']['value']);

        $exif = [];
        foreach ($view->sourceGroups['exif']['tags'] as $tag) {
            $exif[$tag['name']] = $tag;
        }
        self::assertTrue($exif['GPSLatitude']['sensitive']);

        self::assertSame(
            'RAW_UNKNOWN_SENTINEL',
            $view->sourceGroups['other']['tags'][0]['value'],
        );
    }

    public function testNonOwnerCannotReadRawMetadataWorkspace(): void
    {
        $owner = Uuid::fromString('77777777-7777-4777-8777-777777777771');
        $reader = new MetadataWorkspaceReader($this->repository($this->media($owner)));

        $this->expectException(\DomainException::class);
        $reader->read(
            Uuid::fromString('77777777-7777-4777-8777-777777777772'),
            Uuid::fromString('77777777-7777-4777-8777-777777777779'),
        );
    }

    private function media(Uuid $owner): MediaAsset
    {
        return MediaAsset::reconstitute(
            id: Uuid::fromString('77777777-7777-4777-8777-777777777779'),
            ownerId: $owner,
            original: new StorageObjectId('media', 'PRIVATE_STORAGE_SENTINEL/source.jpg'),
            originalFilename: 'photo.jpg',
            mimeType: 'image/jpeg',
            mediaType: MediaType::Image,
            byteSize: 1234,
            checksumSha256: str_repeat('a', 64),
            processingState: ProcessingState::Ready,
            moderationState: ModerationState::Draft,
            createdAt: new DateTimeImmutable('2026-09-30T20:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-09-30T20:00:00+00:00'),
            width: 1600,
            height: 1200,
            title: 'Current title',
            metadata: [
                'exif' => [
                    'Make' => 'Fixture Camera',
                    'GPSLatitude' => 48.123456,
                ],
                'iptc' => ['Keywords' => ['one', 'two']],
                'xmp' => ['Title' => 'Source title'],
                'icc' => ['ProfileDescription' => 'Display P3'],
                'technical' => ['FileType' => 'JPEG'],
                'UnknownNamespace' => 'RAW_UNKNOWN_SENTINEL',
            ],
            metadataProvenance: [
                'title' => 'user',
                'latitude' => 'embedded',
            ],
            latitude: 48.123456,
        );
    }

    private function repository(MediaAsset $media): MediaAssetRepository
    {
        return new class($media) implements MediaAssetRepository {
            public function __construct(private MediaAsset $media)
            {
            }

            public function save(MediaAsset $media): void
            {
                $this->media = $media;
            }

            public function get(Uuid $id): MediaAsset
            {
                if (!$id->equals($this->media->id)) {
                    throw new \DomainException('Media asset not found.');
                }

                return $this->media;
            }
        };
    }
}
