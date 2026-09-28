<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Symfony\Component\Uid\Uuid;

interface PublicSmartCollectionResolver
{
    public function count(Uuid $collectionId): int;

    /** @return list<PublicMediaResult> */
    public function media(
        Uuid $collectionId,
        int $limit = 120,
        int $offset = 0,
    ): array;

    public function cover(Uuid $collectionId): ?PublicSmartCollectionCoverResult;
}
