<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Export;

use Mediarama\Export\Infrastructure\ExifToolPrivacySafeCopyArguments;
use PHPUnit\Framework\TestCase;

final class ExifToolPrivacySafeCopyArgumentsTest extends TestCase
{
    public function testUsesAllowlistScrubAndPreservesRenderingCriticalMetadata(): void
    {
        $arguments = (new ExifToolPrivacySafeCopyArguments())->build();

        self::assertSame('-all=', $arguments[0]);
        self::assertContains('--ICC_Profile:all', $arguments);
        self::assertContains('-TagsFromFile', $arguments);
        self::assertContains('@', $arguments);
        self::assertContains('-ColorSpaceTags', $arguments);
        self::assertContains('-Orientation', $arguments);
        self::assertContains('-XResolution', $arguments);
        self::assertContains('-YResolution', $arguments);
        self::assertContains('-ResolutionUnit', $arguments);

        self::assertNotContains('-EXIF:all', $arguments);
        self::assertNotContains('-XMP:all', $arguments);
        self::assertNotContains('-IPTC:all', $arguments);
    }
}
