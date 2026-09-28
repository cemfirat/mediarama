<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Organization;

use Mediarama\Organization\Application\ApprovedOrganizationAiPresentationGateway;
use Mediarama\Organization\Application\OrganizationAiPresentationAsset;
use Mediarama\Organization\Application\OrganizationAiPresentationAssetReader;
use Mediarama\Organization\Application\OrganizationAiProvider;
use Mediarama\Organization\Application\OrganizationAiProviderRegistry;
use Mediarama\Organization\Application\OrganizationAiRequest;
use Mediarama\Organization\Application\OrganizationAiRequestSummary;
use Mediarama\Organization\Application\OrganizationProposalCandidate;
use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;
use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;
use Mediarama\Organization\Domain\OrganizationProducerKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrganizationAiBoundaryTest extends TestCase
{
    public function testProviderRegistryMayBeEmpty(): void
    {
        self::assertSame(
            [],
            (new OrganizationAiProviderRegistry([]))->available(),
        );
    }

    public function testExternalProviderCannotClaimLocalInference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrganizationAiProviderDescriptor(
            'external-test',
            OrganizationProducerKind::AiExternal,
            'External Test',
            'model',
            null,
            [
                OrganizationAiCapability::TextReasoning,
                OrganizationAiCapability::LocalInference,
            ],
        );
    }

    public function testLocalProviderMustAdvertiseLocalInference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrganizationAiProviderDescriptor(
            'local-test',
            OrganizationProducerKind::AiLocal,
            'Local Test',
            'model',
            null,
            [OrganizationAiCapability::TextReasoning],
        );
    }

    public function testDuplicateProviderKeysFailClosed(): void
    {
        $provider = $this->provider('same-key');

        $this->expectException(\InvalidArgumentException::class);

        new OrganizationAiProviderRegistry([$provider, $provider]);
    }

    public function testRequestSummaryNeverClaimsOriginalsAreSent(): void
    {
        $summary = new OrganizationAiRequestSummary(
            [OrganizationAiCapability::ImageUnderstanding],
            OrganizationAiInputMode::MetadataAndPresentation,
            2,
            ['image' => 2],
            1,
            false,
            false,
        );

        self::assertTrue($summary->sendsMetadata());
        self::assertFalse($summary->sendsOriginals());
    }

    public function testPresentationGatewayCannotReadOutsideApprovedScope(): void
    {
        $approved = Uuid::v7();
        $denied = Uuid::v7();

        $reader = new class implements OrganizationAiPresentationAssetReader {
            public int $readCalls = 0;

            public function available(Uuid $requesterId, array $mediaIds): array
            {
                return $mediaIds;
            }

            public function read(
                Uuid $requesterId,
                Uuid $mediaId,
            ): OrganizationAiPresentationAsset {
                ++$this->readCalls;

                return new OrganizationAiPresentationAsset(
                    $mediaId,
                    'image/png',
                    1,
                    1,
                    'x',
                );
            }
        };

        $gateway = new ApprovedOrganizationAiPresentationGateway(
            Uuid::v7(),
            $reader,
            [$approved],
        );

        self::assertNull($gateway->presentation($denied));
        self::assertSame(0, $reader->readCalls);
        self::assertSame(
            $approved->toRfc4122(),
            $gateway->presentation($approved)?->mediaId->toRfc4122(),
        );
        self::assertSame(1, $reader->readCalls);
    }

    private function provider(string $key): OrganizationAiProvider
    {
        return new class($key) implements OrganizationAiProvider {
            public function __construct(private readonly string $key)
            {
            }

            public function descriptor(): OrganizationAiProviderDescriptor
            {
                return new OrganizationAiProviderDescriptor(
                    $this->key,
                    OrganizationProducerKind::AiExternal,
                    'Test Provider',
                    'test-model',
                    '1',
                    [OrganizationAiCapability::TextReasoning],
                );
            }

            public function estimateCost(
                OrganizationAiRequestSummary $summary,
            ): ?string {
                return null;
            }

            public function propose(
                OrganizationAiRequest $request,
            ): array {
                return [];
            }
        };
    }
}
