<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use Mediarama\Media\Infrastructure\Metadata\ExifToolMetadataParser;
use PHPUnit\Framework\TestCase;

final class ExifToolMetadataParserTest extends TestCase
{
    public function testParsesFamilyOneMetadataAndPreservesQualifiedSnapshotKeys(): void
    {
        $json = json_encode([[
            'SourceFile' => '/tmp/photo.jpg',
            'IFD0:Make' => 'NIKON CORPORATION',
            'IFD0:Model' => 'NIKON Z 8',
            'ExifIFD:ISO' => 100,
            'ExifIFD:FNumber' => 2.8,
            'ExifIFD:ExposureTime' => 0.004,
            'ExifIFD:FocalLength' => 50,
            'ExifIFD:LensModel' => 'NIKKOR Z 50mm f/1.8 S',
            'ExifIFD:DateTimeOriginal' => '2026:09:01 10:00:00',
            'GPS:GPSLatitude' => 48.2082,
            'GPS:GPSLongitude' => 16.3738,
            'IPTC:CopyrightNotice' => 'IPTC fallback copyright',
            'XMP-dc:Title' => 'Fixture title',
            'XMP-dc:Description' => 'Fixture description',
            'XMP-dc:Creator' => ['Fixture Photographer'],
            'XMP-dc:Copy1:Rights' => 'Fixture XMP copyright',
            'XMP-dc:Subject' => ['portrait', 'vienna'],
            'XMP-iptcCore:Location' => 'Vienna',
            'XMP-tiff:Copy1:Make' => 'Duplicate namespace make',
        ]], JSON_THROW_ON_ERROR);

        $metadata = (new ExifToolMetadataParser())->parse($json);

        self::assertSame('NIKON CORPORATION', $metadata->cameraMake);
        self::assertSame('NIKON Z 8', $metadata->cameraModel);
        self::assertSame('NIKKOR Z 50mm f/1.8 S', $metadata->lens);
        self::assertSame(100, $metadata->iso);
        self::assertSame('Fixture title', $metadata->title);
        self::assertSame('Fixture description', $metadata->description);
        self::assertSame('Fixture Photographer', $metadata->creator);
        self::assertSame('Fixture XMP copyright', $metadata->copyright);
        self::assertSame(['portrait', 'vienna'], $metadata->keywords);
        self::assertSame(48.2082, $metadata->latitude);
        self::assertSame(16.3738, $metadata->longitude);
        self::assertSame('Vienna', $metadata->locationName);
        self::assertSame('2026-09-01 10:00:00', $metadata->capturedAt?->format('Y-m-d H:i:s'));
        self::assertSame('NIKON CORPORATION', $metadata->embedded['exif']['IFD0:Make']);
        self::assertSame('Fixture title', $metadata->embedded['xmp']['XMP-dc:Title']);
        self::assertSame('Duplicate namespace make', $metadata->embedded['xmp']['XMP-tiff:Copy1:Make']);
    }

    public function testFallsBackToIptcAndParsesDateOnly(): void
    {
        $json = json_encode([[
            'SourceFile' => '/tmp/photo.jpg',
            'IPTC:DateCreated' => '2024:03:05',
            'IPTC:ObjectName' => 'IPTC title',
            'IPTC:Caption-Abstract' => 'IPTC description',
            'IPTC:By-line' => 'IPTC creator',
            'IPTC:CopyrightNotice' => 'IPTC copyright',
            'IPTC:Sub-location' => 'Vienna Center',
            'IPTC:Keywords' => ['archive', 'vienna'],
        ]], JSON_THROW_ON_ERROR);

        $metadata = (new ExifToolMetadataParser())->parse($json);

        self::assertSame('2024-03-05 00:00:00', $metadata->capturedAt?->format('Y-m-d H:i:s'));
        self::assertSame('IPTC title', $metadata->title);
        self::assertSame('IPTC description', $metadata->description);
        self::assertSame('IPTC creator', $metadata->creator);
        self::assertSame('IPTC copyright', $metadata->copyright);
        self::assertSame('Vienna Center', $metadata->locationName);
        self::assertSame(['archive', 'vienna'], $metadata->keywords);
        self::assertSame('IPTC title', $metadata->embedded['iptc']['IPTC:ObjectName']);
    }

    public function testKeepsCompatibilityWithGenericGroupAliases(): void
    {
        $json = json_encode([[
            'EXIF:Make' => 'Legacy Make',
            'EXIF:ISO' => 200,
            'XMP:Title' => 'Legacy Title',
            'XMP:Subject' => ['legacy'],
            'Composite:GPSLatitude' => 48.2,
            'Composite:GPSLongitude' => 16.3,
        ]], JSON_THROW_ON_ERROR);

        $metadata = (new ExifToolMetadataParser())->parse($json);

        self::assertSame('Legacy Make', $metadata->cameraMake);
        self::assertSame(200, $metadata->iso);
        self::assertSame('Legacy Title', $metadata->title);
        self::assertSame(['legacy'], $metadata->keywords);
        self::assertSame(48.2, $metadata->latitude);
        self::assertSame(16.3, $metadata->longitude);
    }

}
