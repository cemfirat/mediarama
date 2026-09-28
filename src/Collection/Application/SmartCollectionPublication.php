<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

interface SmartCollectionPublication
{
    public function publish(
        Uuid $ownerId,
        Uuid $collectionId,
        SearchIndexPolicy $indexPolicy,
        ?Uuid $coverMediaId = null,
    ): void;

    public function unpublish(
        Uuid $ownerId,
        Uuid $collectionId,
    ): void;
}
