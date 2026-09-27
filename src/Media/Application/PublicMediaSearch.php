<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

interface PublicMediaSearch
{
    /** @return list<PublicMediaSearchResult> */
    public function search(PublicMediaSearchCriteria $criteria): array;
}
