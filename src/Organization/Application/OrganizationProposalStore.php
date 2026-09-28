<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Symfony\Component\Uid\Uuid;

interface OrganizationProposalStore
{
    /**
     * @param list<Uuid> $mediaIds
     */
    public function createRun(
        Uuid $requesterId,
        OrganizationProducer $producer,
        array $mediaIds,
    ): Uuid;

    /**
     * @param list<Uuid> $affectedMediaIds
     * @param list<OrganizationEvidence> $evidence
     */
    public function addProposal(
        Uuid $requesterId,
        Uuid $runId,
        OrganizationProposalPayload $payload,
        string $rationale,
        array $affectedMediaIds,
        array $evidence,
    ): Uuid;

    public function markReadyForReview(
        Uuid $requesterId,
        Uuid $runId,
    ): void;

    public function run(
        Uuid $requesterId,
        Uuid $runId,
    ): OrganizationRunResult;

    /** @return list<OrganizationProposalResult> */
    public function proposals(
        Uuid $requesterId,
        Uuid $runId,
    ): array;

    public function reject(
        Uuid $requesterId,
        Uuid $proposalId,
    ): void;
}
