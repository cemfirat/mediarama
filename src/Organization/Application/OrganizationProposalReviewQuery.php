<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

interface OrganizationProposalReviewQuery
{
    /** @return list<OrganizationProposalReviewItemResult> */
    public function proposals(
        Uuid $requesterId,
        Uuid $runId,
        int $previewLimit = 12,
    ): array;
}
