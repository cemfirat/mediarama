<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationProducer;
use Symfony\Component\Uid\Uuid;

final readonly class DeterministicOrganizationAnalyzer
{
    public function __construct(
        private OrganizationMetadataSnapshotQuery $metadata,
        private DeterministicOrganizationPlanner $planner,
        private OrganizationProposalStore $store,
    ) {
    }

    /**
     * @param list<Uuid> $mediaIds
     */
    public function analyze(
        Uuid $requesterId,
        array $mediaIds,
    ): Uuid {
        // Snapshot/authorization happens before durable run creation so invalid
        // inputs cannot leave a misleading draft run behind.
        $records = $this->metadata->snapshot(
            $requesterId,
            $mediaIds,
        );
        $candidates = $this->planner->plan($records);

        // The store revalidates authorization while persisting the relational
        // scope. This closes the time-of-check gap between analysis and write.
        $runId = $this->store->createRun(
            $requesterId,
            OrganizationProducer::metadata(),
            $mediaIds,
        );

        foreach ($candidates as $candidate) {
            $this->store->addProposal(
                $requesterId,
                $runId,
                $candidate->payload,
                $candidate->rationale,
                $candidate->affectedMediaIds,
                $candidate->evidence,
            );
        }

        if ($candidates === []) {
            $this->store->markNoSuggestions(
                $requesterId,
                $runId,
            );
        } else {
            $this->store->markReadyForReview(
                $requesterId,
                $runId,
            );
        }

        return $runId;
    }
}
