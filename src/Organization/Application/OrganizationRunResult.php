<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use DateTimeImmutable;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationRunStatus;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationRunResult
{
    public function __construct(
        public Uuid $id,
        public Uuid $requesterId,
        public OrganizationProducer $producer,
        public OrganizationRunStatus $status,
        public int $mediaCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
