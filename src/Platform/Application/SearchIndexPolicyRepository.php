<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

interface SearchIndexPolicyRepository
{
    public function setCollectionPolicy(Uuid $collectionId, SearchIndexPolicy $policy): void;

    public function setMediaPolicy(Uuid $mediaId, SearchIndexPolicy $policy): void;
}
