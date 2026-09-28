<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Collection;

use DateTimeImmutable;
use Mediarama\Collection\Application\LibraryFilterSmartCollectionRuleFactory;
use Mediarama\Collection\Application\SmartCollectionRuleFormFactory;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Media\Application\LibraryMediaSearchCriteria;
use PHPUnit\Framework\TestCase;

final class SmartCollectionManagementInputTest extends TestCase
{
    public function testSimpleRuleFormBuildsTypedNormalizedRule(): void
    {
        $factory = new SmartCollectionRuleFormFactory();

        $rule = $factory->create(
            'and',
            ['captured_at', 'rating_average', 'media_type'],
            ['between', 'gte', 'in'],
            ['2026-09-01', '4', 'image, video'],
            ['2026-09-30', '', ''],
        );

        $payload = $rule->payload();

        self::assertSame('and', $payload['op']);
        self::assertSame(
            '2026-09-01T00:00:00+00:00',
            $payload['rules'][0]['value'][0],
        );
        self::assertSame(
            '2026-09-30T00:00:00+00:00',
            $payload['rules'][0]['value'][1],
        );
        self::assertSame(4.0, $payload['rules'][1]['value']);
        self::assertSame(['image', 'video'], $payload['rules'][2]['value']);
    }

    public function testSimpleEditorRefusesToFlattenNestedRule(): void
    {
        $rule = SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'op' => 'or',
                'rules' => [[
                    'field' => 'media_type',
                    'operator' => 'eq',
                    'value' => 'image',
                ]],
            ]],
        ]);

        self::assertNull(
            (new SmartCollectionRuleFormFactory())->editableRows($rule),
        );
    }

    public function testCompatibleLibraryFilterBecomesEquivalentSmartRule(): void
    {
        $criteria = new LibraryMediaSearchCriteria(
            creator: 'Cem',
            cameraModel: 'Nikon Z 8',
            capturedFrom: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            capturedUntil: new DateTimeImmutable('2026-09-30T23:59:59+00:00'),
        );

        $rule = (new LibraryFilterSmartCollectionRuleFactory())->create($criteria);
        $payload = $rule->payload();

        self::assertSame('and', $payload['op']);
        self::assertSame('creator', $payload['rules'][0]['field']);
        self::assertSame('contains', $payload['rules'][0]['operator']);
        self::assertSame('camera_model', $payload['rules'][1]['field']);
        self::assertSame('between', $payload['rules'][2]['operator']);
    }

    public function testFullSmartV1LibraryFacetsRoundTripLosslessly(): void
    {
        $criteria = new LibraryMediaSearchCriteria(
            mediaType: 'video',
            locationName: 'Vienna',
            tag: 'Wedding',
            minimumRating: 4.0,
            orientation: 'landscape',
        );

        $payload = (new LibraryFilterSmartCollectionRuleFactory())
            ->create($criteria)
            ->payload();

        self::assertSame([
            ['field' => 'media_type', 'operator' => 'eq', 'value' => 'video'],
            ['field' => 'location_name', 'operator' => 'contains', 'value' => 'Vienna'],
            ['field' => 'tag', 'operator' => 'has_tag', 'value' => 'Wedding'],
            ['field' => 'rating_average', 'operator' => 'gte', 'value' => 4.0],
            ['field' => 'orientation', 'operator' => 'eq', 'value' => 'landscape'],
        ], $payload['rules']);
    }

    public function testLibraryFacetValueValidationFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LibraryMediaSearchCriteria(mediaType: 'executable');
    }

    public function testUnsupportedLibraryFilterFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LibraryFilterSmartCollectionRuleFactory())->create(
            new LibraryMediaSearchCriteria(
                text: 'free text cannot be serialized losslessly',
            ),
        );
    }

    public function testExactCoordinatePresenceRemainsSearchOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LibraryFilterSmartCollectionRuleFactory())->create(
            new LibraryMediaSearchCriteria(
                hasLocation: true,
            ),
        );
    }

    public function testEmptyLibraryFilterCannotCreateSmartCollection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LibraryFilterSmartCollectionRuleFactory())->create(
            new LibraryMediaSearchCriteria(),
        );
    }
}
