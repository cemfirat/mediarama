<?php

declare(strict_types=1);

namespace Mediarama\Seo\Domain;

use DateTimeImmutable;

final readonly class PublicSitemapVideo
{
    public function __construct(
        public ?string $title,
        public ?string $description,
        public int $processingVersion,
        public ?int $durationMs,
        public DateTimeImmutable $publishedAt,
    ) {
    }
}
