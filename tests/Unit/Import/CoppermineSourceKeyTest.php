<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Import;

use Mediarama\Import\Coppermine\CoppermineSourceKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoppermineSourceKeyTest extends TestCase
{
    public function testBuildsStableNamespacedKey(): void
    {
        $key = new CoppermineSourceKey('gallery-prod_1');

        self::assertSame('gallery-prod_1', $key->id());
        self::assertSame('coppermine:gallery-prod_1', $key->value());
    }

    #[DataProvider('invalidSourceIds')]
    public function testRejectsUnsafeSourceId(string $sourceId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CoppermineSourceKey($sourceId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSourceIds(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'space' => ['gallery one'];
        yield 'slash' => ['gallery/one'];
        yield 'colon' => ['gallery:one'];
        yield 'leading punctuation' => ['-gallery'];
        yield 'too long' => [str_repeat('a', 49)];
    }
}
