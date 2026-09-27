<?php

declare(strict_types=1);

namespace Mediarama\Seo\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class PublicSitemapCollection
{
    /**
     * @param list<PublicSitemapImage> $images
     */
    public function __construct(
        public Uuid $id,
        public array $images,
    ) {
    }
}
