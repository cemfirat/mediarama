<?php

declare(strict_types=1);

namespace Mediarama\Publishing\Domain;

use DateTimeImmutable;

final readonly class PublicPublicationTimeline
{
    public function __construct(
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $updatedAt,
        public ?PublicationOrigin $origin,
        public ?string $source,
    ) {
    }
}
