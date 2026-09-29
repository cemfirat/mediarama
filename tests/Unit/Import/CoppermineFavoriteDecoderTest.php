<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Import;

use Mediarama\Import\Coppermine\CoppermineFavoriteDecoder;
use PHPUnit\Framework\TestCase;

final class CoppermineFavoriteDecoderTest extends TestCase
{
    public function testDecodesCoppermineFavoritePictureIdsWithoutObjects(): void
    {
        $payload = base64_encode(serialize([100, '101', 100, 0, -1, 'invalid']));

        self::assertSame(['100', '101'], (new CoppermineFavoriteDecoder())->decode($payload));
    }

    public function testRejectsInvalidPayloadsAndObjects(): void
    {
        $decoder = new CoppermineFavoriteDecoder();

        self::assertSame([], $decoder->decode('not-base64%%%'));
        self::assertSame([], $decoder->decode(base64_encode(serialize(new \stdClass()))));
        self::assertSame([], $decoder->decode(base64_encode(serialize('100'))));
    }
}
