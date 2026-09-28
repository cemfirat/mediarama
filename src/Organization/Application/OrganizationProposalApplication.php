<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

interface OrganizationProposalApplication
{
    public function apply(
        Uuid $requesterId,
        Uuid $proposalId,
    ): OrganizationProposalApplicationResult;
}
