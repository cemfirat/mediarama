<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use InvalidArgumentException;
use Mediarama\Media\Infrastructure\Image\ImageWatermarkConfiguration;
use PHPUnit\Framework\TestCase;

final class ImageWatermarkConfigurationTest extends TestCase
{
    public function testValidConfigurationNormalizesGravity(): void
    {
        $configuration = new ImageWatermarkConfiguration(
            '',
            25,
            50,
            4,
            'SouthEast',
        );

        self::assertSame(25, $configuration->widthPercent());
        self::assertSame(50, $configuration->opacityPercent());
        self::assertSame(4, $configuration->marginPercent());
        self::assertSame('southeast', $configuration->gravity());
        self::assertSame('SouthEast', $configuration->imageMagickGravity());
    }

    public function testWidthPercentMustBeBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImageWatermarkConfiguration('', 0);
    }

    public function testOpacityPercentMustBeBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImageWatermarkConfiguration('', 18, 101);
    }

    public function testMarginPercentMustBeBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImageWatermarkConfiguration('', 18, 35, 21);
    }

    public function testGravityMustBeFromClosedSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImageWatermarkConfiguration('', 18, 35, 2, 'bottom-right-ish');
    }
}
