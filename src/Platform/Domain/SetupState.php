<?php

declare(strict_types=1);

namespace Mediarama\Platform\Domain;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class SetupState
{
    public function __construct(
        public SetupStatus $status,
        public ?DateTimeImmutable $completedAt,
        public ?Uuid $completedBy,
        public ?SetupCompletionMethod $completedVia,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === SetupStatus::Pending;
    }
}
