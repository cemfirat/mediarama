<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Symfony\Component\Uid\Uuid;

interface PublicGalleryQuery
{
    /** @return list<PublicCollectionResult> */
    public function rootCollections(): array;

    public function collection(Uuid $id): ?PublicCollectionResult;

    /** @return list<PublicCollectionResult> */
    public function childCollections(Uuid $parentId): array;

    /** @return list<PublicMediaResult> */
    public function media(Uuid $collectionId, int $limit = 120, int $offset = 0): array;

    public function canViewMedia(Uuid $mediaId): bool;
}
