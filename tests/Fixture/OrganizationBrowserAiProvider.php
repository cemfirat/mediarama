<?php

declare(strict_types=1);

namespace Mediarama\Tests\Fixture;

use Mediarama\Organization\Application\OrganizationAiProvider;
use Mediarama\Organization\Application\OrganizationAiRequest;
use Mediarama\Organization\Application\OrganizationAiRequestSummary;
use Mediarama\Organization\Application\OrganizationProposalCandidate;
use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducerKind;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalType;

final class OrganizationBrowserAiProvider implements OrganizationAiProvider
{
    public function descriptor(): OrganizationAiProviderDescriptor
    {
        return new OrganizationAiProviderDescriptor(
            'browser-test',
            OrganizationProducerKind::AiExternal,
            'Browser Test AI',
            'browser-test-model',
            '2026-09-28',
            [
                OrganizationAiCapability::TextReasoning,
                OrganizationAiCapability::ImageUnderstanding,
            ],
            'Test adapter receives only the approved Mediarama provider DTO.',
            'Test adapter does not retain provider payloads.',
        );
    }

    public function estimateCost(
        OrganizationAiRequestSummary $summary,
    ): ?string {
        return sprintf(
            'Estimated test cost EUR 0.01 for %d media items.',
            $summary->mediaCount,
        );
    }

    public function propose(
        OrganizationAiRequest $request,
    ): array {
        $presentationAvailable = 0;
        $presentationRequested = 0;

        if (in_array(
            OrganizationAiCapability::ImageUnderstanding,
            $request->summary->capabilities,
            true,
        )) {
            foreach ($request->media as $media) {
                ++$presentationRequested;
                if ($request->presentations->presentation($media->id) !== null) {
                    ++$presentationAvailable;
                }
            }

            if ($presentationAvailable < 1) {
                throw new \RuntimeException(
                    'Browser test provider requires an approved presentation.',
                );
            }
        }

        $creatorPresent = 0;
        $locationPresent = 0;
        foreach ($request->media as $media) {
            if ($media->creator !== null) {
                ++$creatorPresent;
            }
            if ($media->locationName !== null) {
                ++$locationPresent;
            }
        }

        $this->recordSpy([
            'media_count' => count($request->media),
            'presentation_requested' => $presentationRequested,
            'presentation_available' => $presentationAvailable,
            'creator_present' => $creatorPresent,
            'location_present' => $locationPresent,
        ]);

        $failPath = getenv('MEDIARAMA_ORGANIZATION_AI_FAIL_PATH');
        if (
            is_string($failPath)
            && $failPath !== ''
            && is_file($failPath)
        ) {
            throw new \RuntimeException(
                'RAW_PROVIDER_RESPONSE_SENTINEL PROVIDER_CREDENTIAL_SENTINEL',
            );
        }

        $affected = array_map(
            static fn ($media) => $media->id,
            $request->media,
        );

        return [new OrganizationProposalCandidate(
            OrganizationProposalPayload::fromArray(
                OrganizationProposalType::Tag,
                [
                    'version' => 1,
                    'name' => 'AI Browser Candidate',
                ],
            ),
            'The configured test AI adapter proposed a review-only tag.',
            $affected,
            [new OrganizationEvidence(
                OrganizationEvidenceSource::Inference,
                'Inference evidence from the approved test provider scope.',
            )],
        )];
    }

    /** @param array<string,int> $state */
    private function recordSpy(array $state): void
    {
        $path = getenv('MEDIARAMA_ORGANIZATION_AI_SPY_PATH');
        if (!is_string($path) || trim($path) === '') {
            return;
        }

        $previous = [];
        if (is_file($path)) {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
            );
            if (is_array($decoded)) {
                $previous = $decoded;
            }
        }

        $state['calls'] = (int) ($previous['calls'] ?? 0) + 1;

        if (file_put_contents(
            $path,
            json_encode(
                $state,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            LOCK_EX,
        ) === false) {
            throw new \RuntimeException(
                'Unable to persist Organization AI browser spy state.',
            );
        }
    }
}
