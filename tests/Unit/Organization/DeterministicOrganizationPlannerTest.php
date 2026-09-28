<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Organization;

use DateTimeImmutable;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Organization\Application\DeterministicOrganizationPlanner;
use Mediarama\Organization\Application\OrganizationMetadataRecord;
use Mediarama\Organization\Application\OrganizationProposalCandidate;
use Mediarama\Organization\Domain\OrganizationProposalType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DeterministicOrganizationPlannerTest extends TestCase
{
    public function testPlannerIsDeterministicAndProducesValidatedSmartRules(): void
    {
        $records = [];

        for ($index = 1; $index <= 10; ++$index) {
            $records[] = $this->record(
                $index,
                mediaType: $index <= 5 ? 'image' : 'video',
                capturedAt: '2026-09-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).'T10:00:00+00:00',
                cameraModel: $index <= 7 ? 'Nikon Z 8' : 'Other camera',
                lens: $index <= 8 ? '35mm' : '50mm',
                location: 'Vienna',
                rating: $index <= 6 ? 4.5 : 3.0,
                tags: [$index <= 5 ? 'Architecture' : 'Video'],
                hasCollection: true,
            );
        }

        $planner = new DeterministicOrganizationPlanner();
        $first = $planner->plan($records);
        $second = $planner->plan($records);

        self::assertSame(
            $this->signatures($first),
            $this->signatures($second),
        );
        self::assertGreaterThanOrEqual(4, count($first));

        foreach ($first as $candidate) {
            if ($candidate->payload->type !== OrganizationProposalType::SmartCollection) {
                continue;
            }

            $payload = $candidate->payload->payload();
            self::assertArrayHasKey('rule', $payload);
            SmartCollectionRule::fromArray($payload['rule']);
        }

        $serialized = json_encode(
            array_map(
                static fn (OrganizationProposalCandidate $candidate): array =>
                    $candidate->payload->payload(),
                $first,
            ),
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString('latitude', $serialized);
        self::assertStringNotContainsString('longitude', $serialized);
        self::assertStringNotContainsString('metadata', $serialized);
        self::assertStringNotContainsString('storage_key', $serialized);
    }

    public function testPlannerSuppressesGroupsBelowMinimumSupport(): void
    {
        $records = [];
        for ($index = 1; $index <= 4; ++$index) {
            $records[] = $this->record(
                $index,
                mediaType: 'image',
                capturedAt: null,
                cameraModel: 'Small camera group',
                lens: null,
                location: null,
                rating: null,
                tags: [],
                hasCollection: false,
            );
        }

        self::assertSame(
            [],
            (new DeterministicOrganizationPlanner())->plan($records),
        );
    }

    /**
     * @param list<string> $tags
     */
    private function record(
        int $index,
        string $mediaType,
        ?string $capturedAt,
        ?string $cameraModel,
        ?string $lens,
        ?string $location,
        ?float $rating,
        array $tags,
        bool $hasCollection,
    ): OrganizationMetadataRecord {
        return new OrganizationMetadataRecord(
            Uuid::fromString(sprintf(
                '88888888-8888-4888-8888-%012d',
                $index,
            )),
            $mediaType,
            $capturedAt !== null ? new DateTimeImmutable($capturedAt) : null,
            'Fixture Creator',
            'Fixture Camera',
            $cameraModel,
            $lens,
            $location,
            1600,
            1200,
            $rating,
            $tags,
            $hasCollection,
        );
    }

    /**
     * @param list<OrganizationProposalCandidate> $candidates
     * @return list<string>
     */
    private function signatures(array $candidates): array
    {
        $signatures = array_map(
            static function (OrganizationProposalCandidate $candidate): string {
                $media = array_map(
                    static fn (Uuid $id): string => $id->toRfc4122(),
                    $candidate->affectedMediaIds,
                );

                return implode('|', [
                    $candidate->payload->type->value,
                    json_encode(
                        $candidate->payload->payload(),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ),
                    implode(',', $media),
                    $candidate->rationale,
                ]);
            },
            $candidates,
        );

        sort($signatures);

        return $signatures;
    }
}
