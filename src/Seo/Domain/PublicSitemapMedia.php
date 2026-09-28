<?php

declare(strict_types=1);

namespace Mediarama\Seo\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class PublicSitemapMedia
{
    public function __construct(
        public Uuid $id,
        public ?PublicSitemapVideo $video = null,
    ) {
    }
}
