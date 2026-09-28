<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Organization;

use Mediarama\Organization\Application\OrganizationProposalPayloadEditor;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrganizationProposalPayloadEditorTest extends TestCase
{
    public function testSmartReviewEditsPresentationWithoutChangingRule(): void
    {
        $current = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::SmartCollection,
            [
                'version' => 1,
                'title' => 'Before',
                'description' => null,
                'rule' => [
                    'version' => 1,
                    'op' => 'and',
                    'rules' => [[
                        'field' => 'media_type',
                        'operator' => 'eq',
                        'value' => 'image',
                    ]],
                ],
            ],
        );

        $edited = (new OrganizationProposalPayloadEditor())->edit(
            $current,
            [
                'title' => 'After',
                'description' => 'Reviewed description',
            ],
        );

        self::assertSame('After', $edited->payload()['title']);
        self::assertSame(
            $current->payload()['rule'],
            $edited->payload()['rule'],
        );
    }

    public function testProposalTypeCannotBeExpandedThroughEditFields(): void
    {
        $current = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Tag,
            ['version' => 1, 'name' => 'Architecture'],
        );

        $this->expectException(\InvalidArgumentException::class);

        (new OrganizationProposalPayloadEditor())->edit(
            $current,
            ['latitude' => '48.2'],
        );
    }

    public function testCoverEditKeepsCollectionTargetStable(): void
    {
        $collectionId = Uuid::v7()->toRfc4122();
        $current = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Cover,
            [
                'version' => 1,
                'collection_id' => $collectionId,
                'media_id' => Uuid::v7()->toRfc4122(),
            ],
        );
        $replacement = Uuid::v7()->toRfc4122();

        $edited = (new OrganizationProposalPayloadEditor())->edit(
            $current,
            ['media_id' => $replacement],
        );

        self::assertSame($collectionId, $edited->payload()['collection_id']);
        self::assertSame($replacement, $edited->payload()['media_id']);
    }
}
