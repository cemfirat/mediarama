<?php

declare(strict_types=1);

namespace Mediarama\Tests\Fixtures\Organization;

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
use Symfony\Component\Uid\Uuid;

final class BrowserOrganizationAiProvider implements OrganizationAiProvider
{
    public const CALL_LOG = '/tmp/mediarama-ai-browser-provider-calls';

    public function descriptor(): OrganizationAiProviderDescriptor
    {
        return new OrganizationAiProviderDescriptor(
            key: 'browser-fixture',
            producerKind: OrganizationProducerKind::AiExternal,
            providerName: 'CI Visual Provider',
            modelName: 'ci-vision-1',
            modelVersion: '2026-09',
            capabilities: [
                OrganizationAiCapability::TextReasoning,
                OrganizationAiCapability::ImageUnderstanding,
                OrganizationAiCapability::BatchAnalysis,
            ],
            privacyNote: 'CI fixture receives only the explicitly approved Mediarama request DTO.',
            retentionNote: 'CI fixture retains no provider payload.',
        );
    }

    public function estimateCost(
        OrganizationAiRequestSummary $summary,
    ): ?string {
        return sprintf(
            'CI estimate: %d media / %d presentation derivatives',
            $summary->mediaCount,
            $summary->presentationMediaCount,
        );
    }

    public function propose(
        OrganizationAiRequest $request,
    ): array {
        file_put_contents(
            self::CALL_LOG,
            "provider-called\n",
            FILE_APPEND | LOCK_EX,
        );

        $visual = in_array(
            OrganizationAiCapability::ImageUnderstanding,
            $request->summary->capabilities,
            true,
        );

        $ids = [];
        foreach ($request->media as $item) {
            if ($item->creator !== null || $item->locationName !== null) {
                throw new \RuntimeException(
                    'Browser AI fixture received metadata without explicit opt-in.',
                );
            }

            if ($visual) {
                $presentation = $request->presentations->presentation(
                    $item->id,
                );
                if ($presentation === null) {
                    throw new \RuntimeException(
                        'Browser AI fixture did not receive an approved presentation.',
                    );
                }

                if (
                    str_contains($presentation->bytes, 'PRIVATE_ORIGINAL')
                    || str_contains($presentation->bytes, 'PRIVATE_RAW')
                ) {
                    throw new \RuntimeException(
                        'Browser AI fixture received private source data.',
                    );
                }
            }

            $ids[] = $item->id;
        }

        return [
            new OrganizationProposalCandidate(
                OrganizationProposalPayload::fromArray(
                    OrganizationProposalType::Tag,
                    [
                        'version' => 1,
                        'name' => 'AI visual review tag',
                    ],
                ),
                'The configured visual provider proposed one reviewable tag.',
                $ids,
                [
                    new OrganizationEvidence(
                        OrganizationEvidenceSource::Inference,
                        'CI visual inference from approved presentation derivatives.',
                    ),
                ],
            ),
        ];
    }
}
