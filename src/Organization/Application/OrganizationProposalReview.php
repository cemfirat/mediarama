<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Symfony\Component\Uid\Uuid;

interface OrganizationProposalReview
{
    public function edit(
        Uuid $requesterId,
        Uuid $proposalId,
        OrganizationProposalPayload $payload,
    ): void;

    public function accept(
        Uuid $requesterId,
        Uuid $proposalId,
    ): OrganizationProposalApplyResult;

    /**
     * @param list<Uuid> $proposalIds
     * @return list<OrganizationProposalApplyResult>
     */
    public function acceptMany(
        Uuid $requesterId,
        array $proposalIds,
    ): array;

    /** @param list<Uuid> $proposalIds */
    public function rejectMany(
        Uuid $requesterId,
        array $proposalIds,
    ): void;
}
