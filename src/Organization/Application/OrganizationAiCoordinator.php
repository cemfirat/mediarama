<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;
use Mediarama\Organization\Domain\OrganizationAiPreflightStatus;
use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationAiCoordinator
{
    private const MAX_PROVIDER_PROPOSALS = 200;

    public function __construct(
        private OrganizationAiProviderRegistry $providers,
        private OrganizationMetadataSnapshotQuery $metadata,
        private OrganizationAiPresentationAssetReader $presentations,
        private OrganizationAiPreflightStore $preflights,
        private OrganizationProposalStore $proposals,
    ) {
    }

    /**
     * @param list<OrganizationAiCapability> $capabilities
     * @param list<Uuid> $mediaIds
     */
    public function prepare(
        Uuid $requesterId,
        string $providerKey,
        array $capabilities,
        OrganizationAiInputMode $inputMode,
        array $mediaIds,
        bool $includeCreator = false,
        bool $includeLocationName = false,
    ): OrganizationAiPreflightResult {
        $provider = $this->providers->provider($providerKey);
        $descriptor = $provider->descriptor();
        $capabilities = $this->capabilities($capabilities);

        foreach ($capabilities as $capability) {
            if (!$descriptor->supports($capability)) {
                throw new \InvalidArgumentException(sprintf(
                    'Organization AI provider does not support capability "%s".',
                    $capability->value,
                ));
            }
        }

        if (
            in_array(
                OrganizationAiCapability::ImageUnderstanding,
                $capabilities,
                true,
            )
            && $inputMode !== OrganizationAiInputMode::MetadataAndPresentation
        ) {
            throw new \InvalidArgumentException(
                'Image understanding requires approved presentation derivatives.',
            );
        }

        $records = $this->metadata->snapshot(
            $requesterId,
            $mediaIds,
        );

        $mediaTypeCounts = [];
        foreach ($records as $record) {
            $mediaTypeCounts[$record->mediaType] =
                ($mediaTypeCounts[$record->mediaType] ?? 0) + 1;
        }
        ksort($mediaTypeCounts);

        $presentationIds = $inputMode === OrganizationAiInputMode::MetadataAndPresentation
            ? $this->presentations->available($requesterId, $mediaIds)
            : [];

        if (
            in_array(
                OrganizationAiCapability::ImageUnderstanding,
                $capabilities,
                true,
            )
            && $presentationIds === []
        ) {
            throw new \InvalidArgumentException(
                'Image understanding requires at least one readable presentation derivative.',
            );
        }

        $presentationMap = [];
        foreach ($presentationIds as $mediaId) {
            $presentationMap[$mediaId->toRfc4122()] = true;
        }

        $media = array_map(
            static fn (Uuid $mediaId): OrganizationAiPreflightMedia => new OrganizationAiPreflightMedia(
                $mediaId,
                isset($presentationMap[$mediaId->toRfc4122()]),
            ),
            $mediaIds,
        );

        $summary = new OrganizationAiRequestSummary(
            $capabilities,
            $inputMode,
            count($records),
            $mediaTypeCounts,
            count($presentationIds),
            $includeCreator,
            $includeLocationName,
        );

        $costEstimate = $this->costEstimate(
            $provider->estimateCost($summary),
        );

        $preflightId = $this->preflights->create(
            $requesterId,
            $descriptor,
            $capabilities,
            $inputMode,
            $media,
            $mediaTypeCounts,
            $includeCreator,
            $includeLocationName,
            $this->excludedFields(
                $includeCreator,
                $includeLocationName,
            ),
            $costEstimate,
        );

        return $this->preflights->get(
            $requesterId,
            $preflightId,
        );
    }

    public function approve(
        Uuid $requesterId,
        Uuid $preflightId,
    ): OrganizationAiPreflightResult {
        $this->preflights->approve(
            $requesterId,
            $preflightId,
        );

        return $this->preflights->get(
            $requesterId,
            $preflightId,
        );
    }

    public function execute(
        Uuid $requesterId,
        Uuid $preflightId,
    ): Uuid {
        $preflight = $this->preflights->claimApproved(
            $requesterId,
            $preflightId,
        );

        try {
            $provider = $this->providers->provider(
                $preflight->providerKey,
            );
            $descriptor = $provider->descriptor();
            $this->assertProviderUnchanged(
                $preflight,
                $descriptor,
            );

            foreach ($preflight->capabilities as $capability) {
                if (!$descriptor->supports($capability)) {
                    throw new \DomainException(
                        'Organization AI provider capabilities changed after approval.',
                    );
                }
            }
        } catch (\Throwable) {
            $this->preflights->fail(
                $requesterId,
                $preflightId,
                'provider_configuration_changed',
            );

            throw new OrganizationAiProviderExecutionException(
                'Organization AI provider configuration changed after approval.',
            );
        }

        $scope = $this->preflights->media(
            $requesterId,
            $preflightId,
        );
        $mediaIds = array_map(
            static fn (OrganizationAiPreflightMedia $item): Uuid => $item->mediaId,
            $scope,
        );

        try {
            $records = $this->metadata->snapshot(
                $requesterId,
                $mediaIds,
            );
        } catch (\Throwable) {
            $this->preflights->fail(
                $requesterId,
                $preflightId,
                'scope_unavailable',
            );

            throw new OrganizationAiProviderExecutionException(
                'Organization AI approved media scope is no longer available.',
            );
        }

        $recordMap = [];
        foreach ($records as $record) {
            $recordMap[$record->id->toRfc4122()] = $record;
        }

        $approvedPresentationIds = [];
        foreach ($scope as $item) {
            if ($item->sendPresentation) {
                $approvedPresentationIds[] = $item->mediaId;
            }
        }

        if ($approvedPresentationIds !== []) {
            $stillAvailable = $this->presentations->available(
                $requesterId,
                $approvedPresentationIds,
            );

            if (
                $this->uuidSet($stillAvailable)
                !== $this->uuidSet($approvedPresentationIds)
            ) {
                $this->preflights->fail(
                    $requesterId,
                    $preflightId,
                    'presentation_scope_changed',
                );

                throw new OrganizationAiProviderExecutionException(
                    'Organization AI approved presentation scope changed before execution.',
                );
            }
        }

        $inputs = [];
        foreach ($scope as $item) {
            $record = $recordMap[$item->mediaId->toRfc4122()] ?? null;
            if ($record === null) {
                $this->preflights->fail(
                    $requesterId,
                    $preflightId,
                    'scope_unavailable',
                );

                throw new OrganizationAiProviderExecutionException(
                    'Organization AI approved media scope is incomplete.',
                );
            }

            $inputs[] = OrganizationAiMediaInput::fromMetadata(
                $record,
                $preflight->includeCreator,
                $preflight->includeLocationName,
            );
        }

        $request = new OrganizationAiRequest(
            $preflight->summary(),
            $inputs,
            new ApprovedOrganizationAiPresentationGateway(
                $requesterId,
                $this->presentations,
                $approvedPresentationIds,
            ),
        );

        try {
            $candidates = $provider->propose($request);
        } catch (\Throwable) {
            $this->preflights->fail(
                $requesterId,
                $preflightId,
                'provider_failure',
            );

            throw new OrganizationAiProviderExecutionException(
                'Organization AI provider request failed.',
            );
        }

        try {
            $this->validateCandidates(
                $candidates,
                $mediaIds,
            );

            $runId = $this->proposals->createRun(
                $requesterId,
                $preflight->producer,
                $mediaIds,
            );

            try {
                foreach ($candidates as $candidate) {
                    $this->proposals->addProposal(
                        $requesterId,
                        $runId,
                        $candidate->payload,
                        $candidate->rationale,
                        $candidate->affectedMediaIds,
                        $candidate->evidence,
                    );
                }

                if ($candidates === []) {
                    $this->proposals->markNoSuggestions(
                        $requesterId,
                        $runId,
                    );
                } else {
                    $this->proposals->markReadyForReview(
                        $requesterId,
                        $runId,
                    );
                }
            } catch (\Throwable) {
                $this->proposals->markFailed(
                    $requesterId,
                    $runId,
                );

                throw new \RuntimeException(
                    'Organization AI proposals could not be persisted.',
                );
            }

            $this->preflights->complete(
                $requesterId,
                $preflightId,
                $runId,
            );

            return $runId;
        } catch (\Throwable) {
            $current = $this->preflights->get(
                $requesterId,
                $preflightId,
            );

            if ($current->status === OrganizationAiPreflightStatus::Executing) {
                $this->preflights->fail(
                    $requesterId,
                    $preflightId,
                    'proposal_validation_failure',
                );
            }

            throw new OrganizationAiProviderExecutionException(
                'Organization AI provider returned invalid proposal data.',
            );
        }
    }

    /**
     * @param list<OrganizationAiCapability> $capabilities
     * @return list<OrganizationAiCapability>
     */
    private function capabilities(array $capabilities): array
    {
        if ($capabilities === []) {
            throw new \InvalidArgumentException(
                'Organization AI analysis requires at least one capability.',
            );
        }

        $unique = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof OrganizationAiCapability) {
                throw new \InvalidArgumentException(
                    'Organization AI capabilities are invalid.',
                );
            }

            if (isset($unique[$capability->value])) {
                throw new \InvalidArgumentException(
                    'Organization AI capabilities must not contain duplicates.',
                );
            }

            $unique[$capability->value] = $capability;
        }

        ksort($unique);

        return array_values($unique);
    }

    /**
     * @return list<string>
     */
    private function excludedFields(
        bool $includeCreator,
        bool $includeLocationName,
    ): array {
        $fields = [
            'exact_gps',
            'metadata_provenance',
            'original_filename',
            'private_collection_names',
            'raw_metadata',
            'source_storage',
        ];

        if (!$includeCreator) {
            $fields[] = 'creator';
        }

        if (!$includeLocationName) {
            $fields[] = 'coarse_location_name';
        }

        sort($fields);

        return $fields;
    }

    private function costEstimate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $length = iconv_strlen($value, 'UTF-8');
        if ($length === false || $length > 1000) {
            throw new \InvalidArgumentException(
                'Organization AI cost estimate is too long.',
            );
        }

        return $value;
    }

    private function assertProviderUnchanged(
        OrganizationAiPreflightResult $preflight,
        OrganizationAiProviderDescriptor $descriptor,
    ): void {
        if (
            $preflight->providerKey !== $descriptor->key
            || $preflight->producer->kind !== $descriptor->producerKind
            || $preflight->producer->providerName !== $descriptor->providerName
            || $preflight->producer->modelName !== $descriptor->modelName
            || $preflight->producer->modelVersion !== $descriptor->modelVersion
        ) {
            throw new \DomainException(
                'Organization AI provider audit identity changed.',
            );
        }
    }

    /**
     * @param list<OrganizationProposalCandidate> $candidates
     * @param list<Uuid> $scope
     */
    private function validateCandidates(
        array $candidates,
        array $scope,
    ): void {
        if (count($candidates) > self::MAX_PROVIDER_PROPOSALS) {
            throw new \InvalidArgumentException(
                'Organization AI provider returned too many proposals.',
            );
        }

        $scopeSet = $this->uuidSet($scope);

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof OrganizationProposalCandidate) {
                throw new \InvalidArgumentException(
                    'Organization AI provider returned an invalid proposal.',
                );
            }

            if ($candidate->affectedMediaIds === []) {
                throw new \InvalidArgumentException(
                    'Organization AI proposal must affect at least one MediaAsset.',
                );
            }

            foreach ($candidate->affectedMediaIds as $mediaId) {
                if (
                    !$mediaId instanceof Uuid
                    || !isset($scopeSet[$mediaId->toRfc4122()])
                ) {
                    throw new \InvalidArgumentException(
                        'Organization AI proposal references media outside the approved scope.',
                    );
                }
            }
        }
    }

    /**
     * @param list<Uuid> $ids
     * @return array<string,true>
     */
    private function uuidSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            if (!$id instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Organization AI scope contains a non-UUID value.',
                );
            }

            $set[$id->toRfc4122()] = true;
        }

        ksort($set);

        return $set;
    }
}
