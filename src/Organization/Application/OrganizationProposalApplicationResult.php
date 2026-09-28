<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

final readonly class OrganizationProposalApplicationResult
{
    public function __construct(
        public Uuid $proposalId,
        public string $resourceType,
        public Uuid $resourceId,
        public bool $alreadyApplied,
    ) {
    }
}
