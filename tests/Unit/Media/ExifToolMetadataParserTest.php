<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use Mediarama\Media\Infrastructure\Metadata\ExifToolMetadataParser;
use PHPUnit\Framework\TestCase;

final class ExifToolMetadataParserTest extends TestCase
{
    public function testParsesRealExifToolFamilyOneNamespacesAndPreservesBuckets(): void
    {
        $json = json_encode([[
            'SourceFile' => '/tmp/photo.jpg',
            'IFD0:Make' => 'NIKON CORPORATION',
            'IFD0:Model' => 'NIKON Z 8',
            'ExifIFD:ISO' => 100,
            'ExifIFD:FNumber' => 2.8,
            'ExifIFD:ExposureTime' => '1/250',
            'ExifIFD:FocalLength' => '50 mm',
            'IPTC:CopyrightNotice' => 'Example',
            'XMP-dc:Title' => 'Vienna Portrait',
            'XMP-dc:Creator' => ['Example Photographer'],
            'XMP-dc:Subject' => ['portrait', 'vienna'],
            'XMP-iptcCore:Location' => 'Vienna',
            'XMP-exif:GPSLatitude' => 48.2082,
            'XMP-exif:GPSLongitude' => 16.3738,
        ]], JSON_THROW_ON_ERROR);

        $metadata = (new ExifToolMetadataParser())->parse($json);

        self::assertSame('NIKON CORPORATION', $metadata->cameraMake);
        self::assertSame('NIKON Z 8', $metadata->cameraModel);
        self::assertSame(100, $metadata->iso);
        self::assertSame('Vienna Portrait', $metadata->title);
        self::assertSame('Example Photographer', $metadata->creator);
        self::assertSame(['portrait', 'vienna'], $metadata->keywords);
        self::assertSame('Example', $metadata->copyright);
        self::assertSame('Vienna', $metadata->locationName);
        self::assertSame(48.2082, $metadata->latitude);
        self::assertSame(16.3738, $metadata->longitude);
        self::assertSame('NIKON CORPORATION', $metadata->embedded['exif']['Make']);
        self::assertSame('Vienna Portrait', $metadata->embedded['xmp']['Title']);
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
