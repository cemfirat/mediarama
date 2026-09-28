<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

interface OwnedPresentationManagement
{
    public function updateMedia(
        Uuid $ownerId,
        Uuid $mediaId,
        ?string $title,
        ?string $description,
    ): void;

    public function updateCollection(
        Uuid $ownerId,
        Uuid $collectionId,
        ?string $title,
        ?string $description,
    ): void;

    public function setCollectionCover(
        Uuid $ownerId,
        Uuid $collectionId,
        Uuid $mediaId,
    ): void;
}
