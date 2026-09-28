<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Organization;

use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProducerKind;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrganizationProposalPayloadTest extends TestCase
{
    public function testAllFoundationProposalTypesNormalizeThroughApplicationOwnedPayloads(): void
    {
        $mediaId = Uuid::v7()->toRfc4122();
        $collectionId = Uuid::v7()->toRfc4122();

        $smart = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::SmartCollection,
            [
                'version' => 1,
                'title' => 'Vienna videos',
                'description' => 'A deterministic Smart Collection suggestion.',
                'rule' => [
                    'version' => 1,
                    'op' => 'and',
                    'rules' => [[
                        'field' => 'media_type',
                        'operator' => 'eq',
                        'value' => 'video',
                    ]],
                ],
            ],
        );
        self::assertSame('video', $smart->payload()['rule']['rules'][0]['value']);

        $manual = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ManualCollection,
            [
                'version' => 1,
                'title' => 'Architecture',
                'description' => null,
            ],
        );
        self::assertSame('Architecture', $manual->payload()['title']);

        $tag = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Tag,
            ['version' => 1, 'name' => 'Night photography'],
        );
        self::assertSame('Night photography', $tag->payload()['name']);

        $review = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ReviewBucket,
            [
                'version' => 1,
                'title' => 'Needs review',
                'description' => 'Media that does not fit the current structure.',
            ],
        );
        self::assertSame('Needs review', $review->payload()['title']);

        $text = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::TitleDescription,
            [
                'version' => 1,
                'target_type' => 'media',
                'target_id' => $mediaId,
                'title' => 'New title',
                'description' => null,
            ],
        );
        self::assertSame($mediaId, $text->payload()['target_id']);

        $cover = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Cover,
            [
                'version' => 1,
                'collection_id' => $collectionId,
                'media_id' => $mediaId,
            ],
        );
        self::assertSame($collectionId, $cover->payload()['collection_id']);
    }

    public function testUnknownOrPrivacySensitivePayloadKeysFailClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ManualCollection,
            [
                'version' => 1,
                'title' => 'Unsafe',
                'description' => null,
                'latitude' => 48.2,
            ],
        );
    }

    public function testSmartProposalUsesNormalSmartRuleValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrganizationProposalPayload::fromArray(
            OrganizationProposalType::SmartCollection,
            [
                'version' => 1,
                'title' => 'Unsafe GPS rule',
                'description' => null,
                'rule' => [
                    'version' => 1,
                    'op' => 'and',
                    'rules' => [[
                        'field' => 'latitude',
                        'operator' => 'eq',
                        'value' => 48.2,
                    ]],
                ],
            ],
        );
    }

    public function testMetadataProducerCarriesNoProviderIdentity(): void
    {
        $producer = OrganizationProducer::metadata();

        self::assertSame(OrganizationProducerKind::Metadata, $producer->kind);
        self::assertNull($producer->providerName);
        self::assertNull($producer->modelName);
        self::assertNull($producer->modelVersion);
    }

    public function testAiProducerRecordsMinimalAuditIdentity(): void
    {
        $producer = OrganizationProducer::aiExternal(
            'provider-example',
            'vision-model',
            '2026-09',
        );

        self::assertSame(OrganizationProducerKind::AiExternal, $producer->kind);
        self::assertSame('provider-example', $producer->providerName);
        self::assertSame('vision-model', $producer->modelName);
        self::assertSame('2026-09', $producer->modelVersion);
    }

    public function testEvidenceDistinguishesMetadataFromInference(): void
    {
        $metadata = new OrganizationEvidence(
            OrganizationEvidenceSource::Metadata,
            'Captured during the same month.',
        );
        $inference = new OrganizationEvidence(
            OrganizationEvidenceSource::Inference,
            'Visual subjects appear related.',
        );

        self::assertSame(OrganizationEvidenceSource::Metadata, $metadata->source);
        self::assertSame(OrganizationEvidenceSource::Inference, $inference->source);
    }
}
