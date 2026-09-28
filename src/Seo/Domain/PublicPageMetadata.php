<?php

declare(strict_types=1);

namespace Mediarama\Seo\Domain;

final readonly class PublicPageMetadata
{
    public function __construct(
        public string $pageTitle,
        public string $socialTitle,
        public string $description,
        public string $canonicalUrl,
        public ?string $imageUrl,
        public ?string $imageAlt,
        public ?string $structuredDataJson,
    ) {
    }
}
