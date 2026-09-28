<?php

declare(strict_types=1);

namespace Mediarama\Seo\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class PublicSitemapImage
{
    public function __construct(
        public Uuid $mediaId,
        public int $processingVersion,
        public string $profile,
    ) {
    }
}
