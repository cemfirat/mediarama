<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use DateTimeImmutable;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Symfony\Component\Uid\Uuid;

final readonly class SmartCollectionManagementResult
{
    public function __construct(
        public Uuid $id,
        public string $title,
        public SmartCollectionRule $rule,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
