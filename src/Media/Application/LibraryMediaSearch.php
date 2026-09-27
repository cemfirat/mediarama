<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

interface LibraryMediaSearch
{
    /** @return list<LibraryMediaSearchResult> */
    public function search(Uuid $actorId, LibraryMediaSearchCriteria $criteria): array;
}
