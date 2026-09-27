<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Export;

use Mediarama\Export\Infrastructure\ExifToolSanitizedCopyArguments;
use PHPUnit\Framework\TestCase;

final class ExifToolSanitizedCopyArgumentsTest extends TestCase
{
    public function testUsesAllowlistScrubAndPreservesRenderingCriticalMetadata(): void
    {
        $arguments = (new ExifToolSanitizedCopyArguments())->build();

        self::assertSame('-all=', $arguments[0]);
        self::assertContains('-CommonIFD0=', $arguments);
        self::assertContains('--ICC_Profile:all', $arguments);
        self::assertContains('-TagsFromFile', $arguments);
        self::assertContains('@', $arguments);
        self::assertContains('-ColorSpaceTags', $arguments);
        self::assertContains('-Orientation', $arguments);
        self::assertContains('-PNG:Gamma', $arguments);
        self::assertContains('-PNG:SRGBRendering', $arguments);
        self::assertContains('-XResolution', $arguments);
        self::assertContains('-YResolution', $arguments);
        self::assertContains('-ResolutionUnit', $arguments);

        self::assertNotContains('-EXIF:all', $arguments);
        self::assertNotContains('-XMP:all', $arguments);
        self::assertNotContains('-IPTC:all', $arguments);
    }
}
