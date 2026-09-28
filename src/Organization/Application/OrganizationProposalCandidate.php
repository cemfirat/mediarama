<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationProposalCandidate
{
    /**
     * @param list<Uuid> $affectedMediaIds
     * @param list<OrganizationEvidence> $evidence
     */
    public function __construct(
        public OrganizationProposalPayload $payload,
        public string $rationale,
        public array $affectedMediaIds,
        public array $evidence,
    ) {
    }

    public function support(): int
    {
        return count($this->affectedMediaIds);
    }
}
