<?php

declare(strict_types=1);

namespace Mediarama\Collection\Domain;

use DateTimeImmutable;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

final class Collection
{
    private function __construct(
        public readonly Uuid $id,
        public readonly ?Uuid $ownerId,
        public string $title,
        public ?string $description,
        public Visibility $visibility,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?Uuid $parentId = null,
        public ?Uuid $coverMediaId = null,
        public ?string $slug = null,
        public int $position = 0,
        public SearchIndexPolicy $searchIndexPolicy = SearchIndexPolicy::Inherit,
        public CollectionMode $mode = CollectionMode::Manual,
        public ?SmartCollectionRule $smartRule = null,
    ) {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('Collection title must not be empty.');
        }

        if (
            ($mode === CollectionMode::Manual && $smartRule !== null)
            || ($mode === CollectionMode::Smart && $smartRule === null)
        ) {
            throw new \InvalidArgumentException('Collection mode and Smart rule are inconsistent.');
        }
    }

    public static function create(
        ?Uuid $ownerId,
        string $title,
        Visibility $visibility = Visibility::Private,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            Uuid::v7(),
            $ownerId,
            trim($title),
            null,
            $visibility,
            $now,
            $now,
        );
    }

    public static function createSmart(
        Uuid $ownerId,
        string $title,
        SmartCollectionRule $rule,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            Uuid::v7(),
            $ownerId,
            trim($title),
            null,
            Visibility::Private,
            $now,
            $now,
            mode: CollectionMode::Smart,
            smartRule: $rule,
        );
    }
}
