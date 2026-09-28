<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Symfony\Component\Uid\Uuid;

interface SmartCollectionResolver
{
    public function count(Uuid $actorId, Uuid $collectionId): int;

    /** @return list<SmartCollectionMediaResult> */
    public function resolve(
        Uuid $actorId,
        Uuid $collectionId,
        int $limit = 50,
        int $offset = 0,
    ): array;
}
