<?php

declare(strict_types=1);

namespace Mediarama\Seo\Application;

use Mediarama\Seo\Domain\PublicSitemapCollection;
use Mediarama\Seo\Domain\PublicSitemapMedia;

interface PublicSitemapQuery
{
    public function indexableCollectionCount(): int;

    /**
     * @return list<PublicSitemapCollection>
     */
    public function indexableCollections(int $limit, int $offset): array;

    public function indexableMediaCount(): int;

    /**
     * @return list<PublicSitemapMedia>
     */
    public function indexableMedia(int $limit, int $offset): array;
}
