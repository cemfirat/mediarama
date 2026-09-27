<?php

declare(strict_types=1);

namespace Mediarama\Seo\Application;

use Mediarama\Seo\Domain\PublicSitemapCollection;

interface PublicSitemapQuery
{
    public function indexableCollectionCount(): int;

    /**
     * @return list<PublicSitemapCollection>
     */
    public function indexableCollections(int $limit, int $offset): array;
}
