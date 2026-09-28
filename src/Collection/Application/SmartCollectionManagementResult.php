<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use DateTimeImmutable;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Domain\Visibility;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

final readonly class SmartCollectionManagementResult
{
    public function __construct(
        public Uuid $id,
        public string $title,
        public ?string $description,
        public SmartCollectionRule $rule,
        public Visibility $visibility,
        public SearchIndexPolicy $searchIndexPolicy,
        public ?Uuid $coverMediaId,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->visibility === Visibility::Public;
    }
}
