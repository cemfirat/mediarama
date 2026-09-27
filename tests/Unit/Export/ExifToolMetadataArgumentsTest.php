<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Export;

use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Export\Infrastructure\ExifToolMetadataArguments;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use PHPUnit\Framework\TestCase;

final class ExifToolMetadataArgumentsTest extends TestCase
{
    public function testPrivacySafeRemovesExactAndDescriptiveLocation(): void
    {
        $media = $this->media();
        $media->title = 'Public title';
        $media->locationName = '12 Example Street';
        $media->latitude = 48.2082;
        $media->longitude = 16.3738;

        $arguments = (new ExifToolMetadataArguments())->build(
            $media,
            MetadataExportPolicy::privacySafe(),
        );

        self::assertContains('-GPS:all=', $arguments);
        self::assertContains('-XMP-exif:GPSLatitude=', $arguments);
        self::assertContains('-XMP-exif:GPSLongitude=', $arguments);
        self::assertContains('-XMP-iptcCore:Location=', $arguments);
        self::assertContains('-IPTC:Sub-location=', $arguments);
        self::assertContains('-XMP-dc:Title=Public title', $arguments);

        self::assertNotContains('-XMP-iptcCore:Location=12 Example Street', $arguments);
        self::assertNotContains('-XMP-exif:GPSLatitude=48.2082', $arguments);
        self::assertNotContains('-XMP-exif:GPSLongitude=16.3738', $arguments);
    }

    public function testCurrentProfileKeepsCanonicalLocationAndCoordinates(): void
    {
        $media = $this->media();
        $media->locationName = 'Vienna';
        $media->latitude = 48.21;
        $media->longitude = 16.37;

        $arguments = (new ExifToolMetadataArguments())->build(
            $media,
            new MetadataExportPolicy(MetadataExportProfile::Current),
        );

        self::assertContains('-XMP-iptcCore:Location=Vienna', $arguments);
        self::assertContains('-XMP-exif:GPSLatitude=48.21', $arguments);
        self::assertContains('-XMP-exif:GPSLongitude=16.37', $arguments);
    }

    public function testCustomProfileCanExplicitlyIncludeDescriptiveLocationWithoutGps(): void
    {
        $media = $this->media();
        $media->locationName = 'Vienna';
        $media->latitude = 48.21;
        $media->longitude = 16.37;

        $arguments = (new ExifToolMetadataArguments())->build(
            $media,
            new MetadataExportPolicy(
                MetadataExportProfile::Custom,
                ['location_name'],
            ),
        );

        self::assertSame(['-XMP-iptcCore:Location=Vienna'], $arguments);
    }

    public function testCustomProfileCanExplicitlyIncludeGpsWithoutOtherMetadata(): void
    {
        $media = $this->media();
        $media->title = 'Not selected';
        $media->locationName = 'Not selected';
        $media->latitude = 48.21;
        $media->longitude = 16.37;

        $arguments = (new ExifToolMetadataArguments())->build(
            $media,
            new MetadataExportPolicy(
                MetadataExportProfile::Custom,
                ['latitude', 'longitude'],
            ),
        );

        self::assertSame([
            '-XMP-exif:GPSLatitude=48.21',
            '-XMP-exif:GPSLongitude=16.37',
        ], $arguments);
    }

    private function media(): MediaAsset
    {
        return MediaAsset::create(
            ownerId: null,
            original: new StorageObjectId('media', 'originals/test/source'),
            originalFilename: 'test.jpg',
            mimeType: 'image/jpeg',
            mediaType: MediaType::Image,
            byteSize: 123,
            checksumSha256: str_repeat('a', 64),
        );
    }
}
