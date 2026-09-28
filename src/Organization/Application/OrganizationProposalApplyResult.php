<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationAppliedEntityType;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationProposalApplyResult
{
    public function __construct(
        public Uuid $proposalId,
        public OrganizationAppliedEntityType $entityType,
        public Uuid $entityId,
        public bool $alreadyApplied = false,
    ) {
    }
}
