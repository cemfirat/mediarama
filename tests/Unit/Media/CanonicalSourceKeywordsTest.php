<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use Mediarama\Media\Domain\CanonicalSourceKeywords;
use PHPUnit\Framework\TestCase;

final class CanonicalSourceKeywordsTest extends TestCase
{
    public function testExifToolPriorityPrefersDublinCoreSubject(): void
    {
        self::assertSame([
            'values' => ['portrait', 'vienna'],
            'tag' => 'XMP-dc:Subject',
        ], CanonicalSourceKeywords::fromExifTool([
            'XMP-dc:Subject' => ['portrait', 'vienna', 'portrait'],
            'IPTC:Keywords' => ['fallback'],
        ]));
    }

    public function testStoredSnapshotAcceptsEquivalentListsInDifferentOrder(): void
    {
        self::assertSame([
            'values' => ['portrait', 'vienna'],
            'tag' => 'XMP:Subject + IPTC:Keywords',
        ], CanonicalSourceKeywords::fromStoredSnapshot([
            'xmp' => ['Subject' => ['portrait', 'vienna']],
            'iptc' => ['Keywords' => ['Vienna', 'Portrait']],
        ]));
    }

    public function testStoredSnapshotRejectsConflictingKeywordSets(): void
    {
        self::assertNull(CanonicalSourceKeywords::fromStoredSnapshot([
            'xmp' => ['Subject' => ['portrait', 'vienna']],
            'iptc' => ['Keywords' => ['portrait', 'graz']],
        ]));
    }

    public function testBlankAndUnsupportedValuesRemainUnavailable(): void
    {
        self::assertNull(CanonicalSourceKeywords::fromStoredSnapshot([
            'xmp' => ['Subject' => [' ', []]],
            'iptc' => ['Keywords' => null],
        ]));
    }
}
