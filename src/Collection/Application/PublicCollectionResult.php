<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class PublicCollectionResult
{
    public function __construct(
        public Uuid $id,
        public string $title,
        public ?string $description,
        public int $mediaCount,
        public int $childCount,
        public ?Uuid $coverMediaId,
        public ?int $coverThumbnailVersion,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $publicUpdatedAt,
        public bool $indexable,
    ) {
    }
}
