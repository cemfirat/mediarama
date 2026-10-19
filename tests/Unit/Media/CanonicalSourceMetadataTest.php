<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use Mediarama\Media\Domain\CanonicalSourceMetadata;
use PHPUnit\Framework\TestCase;

final class CanonicalSourceMetadataTest extends TestCase
{
    public function testExifToolProjectionUsesExplicitPriorityAndReportsExactTag(): void
    {
        $source = CanonicalSourceMetadata::fromExifTool(
            [
                'IPTC:ObjectName' => 'IPTC title',
                'XMP:Title' => 'Generic XMP title',
                'XMP-dc:Title' => ['Dublin Core title'],
            ],
            'title',
        );

        self::assertSame([
            'value' => 'Dublin Core title',
            'tag' => 'XMP-dc:Title',
        ], $source);
    }

    public function testStoredSnapshotAcceptsOnlyUnambiguousMappedValue(): void
    {
        $source = CanonicalSourceMetadata::fromStoredSnapshot(
            [
                'xmp' => [
                    'Title' => 'Source title',
                    'XMP:Title' => 'Source title',
                ],
                'iptc' => [
                    'ObjectName' => 'Source title',
                ],
                'other' => [
                    'TitleLikeButUnmapped' => 'Must not be considered',
                ],
            ],
            'title',
        );

        self::assertNotNull($source);
        self::assertSame('Source title', $source['value']);
        self::assertSame('XMP:Title + IPTC:ObjectName', $source['tag']);
    }

    public function testStoredSnapshotFailsClosedWhenMappedNamespacesDisagree(): void
    {
        self::assertNull(CanonicalSourceMetadata::fromStoredSnapshot(
            [
                'xmp' => ['Title' => 'XMP title'],
                'iptc' => ['ObjectName' => 'IPTC title'],
            ],
            'title',
        ));
    }

    public function testUnsupportedOrBlankSourceValuesAreUnavailable(): void
    {
        self::assertNull(CanonicalSourceMetadata::fromExifTool(
            ['XMP-dc:Title' => '   '],
            'title',
        ));
        self::assertNull(CanonicalSourceMetadata::fromStoredSnapshot(
            ['xmp' => ['Title' => ['   ']]],
            'title',
        ));
        self::assertNull(CanonicalSourceMetadata::fromStoredSnapshot(
            ['xmp' => ['Title' => 'Source title']],
            'camera_model',
        ));
        self::assertSame(
            ['title', 'description', 'creator', 'copyright', 'location_name'],
            CanonicalSourceMetadata::editableFields(),
        );
    }
}
