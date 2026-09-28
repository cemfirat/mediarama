<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

interface MediaTagManagement
{
    /**
     * @param list<Uuid> $mediaIds
     */
    public function tagOwnedMedia(
        Uuid $ownerId,
        string $name,
        array $mediaIds,
    ): Uuid;
}
