<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Symfony\Component\Uid\Uuid;

interface ManualCollectionManagement
{
    /**
     * @param list<Uuid> $mediaIds
     */
    public function createPrivate(
        Uuid $ownerId,
        string $title,
        ?string $description,
        array $mediaIds,
    ): Uuid;
}
