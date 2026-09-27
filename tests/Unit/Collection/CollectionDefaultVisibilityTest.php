<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Collection;

use Mediarama\Collection\Domain\Collection;
use Mediarama\Collection\Domain\Visibility;
use PHPUnit\Framework\TestCase;

final class CollectionDefaultVisibilityTest extends TestCase
{
    public function testNewCollectionIsPrivateUnlessVisibilityIsExplicit(): void
    {
        $collection = Collection::create(null, 'Private by default');

        self::assertSame(Visibility::Private, $collection->visibility);
    }

    public function testPublicCollectionRequiresExplicitVisibility(): void
    {
        $collection = Collection::create(
            null,
            'Deliberately public',
            Visibility::Public,
        );

        self::assertSame(Visibility::Public, $collection->visibility);
    }
}
