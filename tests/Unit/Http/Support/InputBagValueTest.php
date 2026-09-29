<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Http\Support;

use Mediarama\Http\Support\InputBagValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class InputBagValueTest extends TestCase
{
    public function testStrictIntegerAndBooleanUseValidValuesAndDefaults(): void
    {
        $input = new InputBag([
            'limit' => '25',
            'enabled' => 'true',
        ]);

        self::assertSame(25, InputBagValue::integer($input, 'limit', 50));
        self::assertTrue(InputBagValue::boolean($input, 'enabled'));
        self::assertSame(50, InputBagValue::integer($input, 'missing', 50));
        self::assertFalse(InputBagValue::boolean($input, 'missing'));
    }

    public function testStrictIntegerNormalizesSymfonyConversionFailure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid integer parameter "limit".');

        InputBagValue::integer(new InputBag(['limit' => 'not-an-integer']), 'limit');
    }

    public function testStrictBooleanNormalizesSymfonyConversionFailure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid boolean parameter "enabled".');

        InputBagValue::boolean(new InputBag(['enabled' => 'not-a-boolean']), 'enabled');
    }

    public function testTolerantValuesFallBackForMalformedUiHints(): void
    {
        $input = new InputBag([
            'page' => 'not-an-integer',
            'saved' => 'not-a-boolean',
        ]);

        self::assertSame(1, InputBagValue::integerOrDefault($input, 'page', 1));
        self::assertFalse(InputBagValue::booleanOrDefault($input, 'saved'));
    }
}
