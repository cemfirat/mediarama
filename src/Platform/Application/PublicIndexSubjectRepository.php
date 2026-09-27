<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

interface PublicIndexSubjectRepository
{
    /**
     * Returns null when the Collection is not effectively public.
     */
    public function collectionPolicy(Uuid $collectionId): ?SearchIndexPolicy;

    /**
     * Returns null when the MediaAsset is not ready, published and reachable
     * through at least one effectively-public Collection.
     */
    public function mediaPolicy(Uuid $mediaId): ?SearchIndexPolicy;
}
