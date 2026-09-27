<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

interface MediaSearch
{
    /** @return list<MediaSearchResult> */
    public function search(Uuid $actorId, MediaSearchCriteria $criteria): array;
}
