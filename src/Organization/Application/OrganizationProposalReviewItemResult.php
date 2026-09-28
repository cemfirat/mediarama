<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use DateTimeImmutable;
use Mediarama\Organization\Domain\OrganizationAppliedEntityType;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalStatus;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationProposalReviewItemResult
{
    /**
     * @param list<OrganizationEvidence> $evidence
     * @param list<OrganizationProposalMediaPreviewResult> $mediaPreview
     */
    public function __construct(
        public Uuid $id,
        public Uuid $runId,
        public OrganizationProposalStatus $status,
        public OrganizationProposalPayload $payload,
        public string $rationale,
        public int $affectedMediaCount,
        public array $evidence,
        public array $mediaPreview,
        public ?DateTimeImmutable $reviewedAt,
        public ?OrganizationAppliedEntityType $appliedEntityType,
        public ?Uuid $appliedEntityId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
