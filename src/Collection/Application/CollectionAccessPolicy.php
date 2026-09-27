<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Symfony\Component\Uid\Uuid;

interface CollectionAccessPolicy
{
    public function canView(?Uuid $userId, Uuid $collectionId): bool;

    public function canAddMedia(Uuid $userId, Uuid $collectionId): bool;
}
