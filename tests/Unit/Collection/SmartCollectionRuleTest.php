<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Collection;

use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Domain\Collection;
use Mediarama\Collection\Domain\CollectionMode;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Domain\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SmartCollectionRuleTest extends TestCase
{
    public function testNestedRuleNormalizesAndCompilesOnlyAllowlistedFields(): void
    {
        $rule = SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [
                [
                    'field' => 'media_type',
                    'operator' => 'in',
                    'value' => ['image', 'video'],
                ],
                [
                    'field' => 'captured_at',
                    'operator' => 'gte',
                    'value' => '2026-09-01T00:00:00+02:00',
                ],
                [
                    'op' => 'or',
                    'rules' => [
                        [
                            'field' => 'camera_model',
                            'operator' => 'contains',
                            'value' => 'Nikon Z 8',
                        ],
                        [
                            'field' => 'orientation',
                            'operator' => 'eq',
                            'value' => 'portrait',
                        ],
                    ],
                ],
            ],
        ]);

        $payload = $rule->payload();
        self::assertSame(
            '2026-08-31T22:00:00+00:00',
            $payload['rules'][1]['value'],
        );

        $compiled = (new SmartCollectionRuleCompiler())->compile($rule);

        self::assertStringContainsString('m.media_type IN', $compiled->sql);
        self::assertStringContainsString('m.captured_at >=', $compiled->sql);
        self::assertStringContainsString('m.camera_model', $compiled->sql);
        self::assertStringContainsString('m.width < m.height', $compiled->sql);
        self::assertStringNotContainsString('Nikon Z 8', $compiled->sql);
        self::assertContains('Nikon Z 8', array_values($compiled->parameters));
    }

    /** @param array{field:string,operator:string,value:mixed} $predicate */
    #[DataProvider('v1PredicateProvider')]
    public function testEveryV1FieldFamilyCompiles(array $predicate): void
    {
        $rule = SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [$predicate],
        ]);

        $compiled = (new SmartCollectionRuleCompiler())->compile($rule);

        self::assertNotSame('', trim($compiled->sql));
    }

    /** @return iterable<string,array{0:array{field:string,operator:string,value:mixed}}> */
    public static function v1PredicateProvider(): iterable
    {
        yield 'media type' => [[
            'field' => 'media_type',
            'operator' => 'eq',
            'value' => 'image',
        ]];
        yield 'capture range' => [[
            'field' => 'captured_at',
            'operator' => 'between',
            'value' => [
                '2026-01-01T00:00:00+00:00',
                '2026-12-31T23:59:59+00:00',
            ],
        ]];
        yield 'creator' => [[
            'field' => 'creator',
            'operator' => 'contains',
            'value' => 'Cem',
        ]];
        yield 'camera make' => [[
            'field' => 'camera_make',
            'operator' => 'eq',
            'value' => 'Nikon',
        ]];
        yield 'camera model' => [[
            'field' => 'camera_model',
            'operator' => 'neq',
            'value' => 'Unknown',
        ]];
        yield 'lens' => [[
            'field' => 'lens',
            'operator' => 'in',
            'value' => ['35mm', '50mm'],
        ]];
        yield 'coarse location name' => [[
            'field' => 'location_name',
            'operator' => 'contains',
            'value' => 'Vienna',
        ]];
        yield 'tag' => [[
            'field' => 'tag',
            'operator' => 'has_tag',
            'value' => 'Wedding',
        ]];
        yield 'rating average' => [[
            'field' => 'rating_average',
            'operator' => 'gte',
            'value' => 4,
        ]];
        yield 'orientation' => [[
            'field' => 'orientation',
            'operator' => 'in',
            'value' => ['portrait', 'landscape'],
        ]];
    }

    public function testInjectionLookingValueIsAlwaysBoundData(): void
    {
        $injection = "Nikon'); DROP TABLE media_assets; --";
        $rule = SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'field' => 'camera_model',
                'operator' => 'eq',
                'value' => $injection,
            ]],
        ]);

        $compiled = (new SmartCollectionRuleCompiler())->compile($rule);

        self::assertStringNotContainsString($injection, $compiled->sql);
        self::assertSame([$injection], array_values($compiled->parameters));
        self::assertStringContainsString(':smart_1', $compiled->sql);
    }

    public function testTagRuleAcceptsSlugOrNameWithoutEmbeddingValueInSql(): void
    {
        $rule = SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'field' => 'tag',
                'operator' => 'has_tag',
                'value' => 'Wedding',
            ]],
        ]);

        $compiled = (new SmartCollectionRuleCompiler())->compile($rule);

        self::assertStringContainsString('media_tags', $compiled->sql);
        self::assertStringContainsString('smart_t.slug', $compiled->sql);
        self::assertStringContainsString('smart_t.name', $compiled->sql);
        self::assertStringNotContainsString('Wedding', $compiled->sql);
        self::assertSame(['Wedding', 'Wedding'], array_values($compiled->parameters));
    }

    #[DataProvider('rejectedFieldProvider')]
    public function testSensitiveOrArbitraryFieldsAreRejected(string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'field' => $field,
                'operator' => 'eq',
                'value' => 'x',
            ]],
        ]);
    }

    /** @return iterable<string,array{0:string}> */
    public static function rejectedFieldProvider(): iterable
    {
        yield 'exact latitude' => ['latitude'];
        yield 'exact longitude' => ['longitude'];
        yield 'raw metadata' => ['metadata'];
        yield 'metadata provenance' => ['metadata_provenance'];
        yield 'SQL-looking field' => ['camera_model) OR TRUE --'];
    }

    public function testInvalidFieldOperatorPairIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'field' => 'tag',
                'operator' => 'contains',
                'value' => 'wedding',
            ]],
        ]);
    }

    public function testRuleDepthIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'op' => 'and',
                'rules' => [[
                    'op' => 'and',
                    'rules' => [[
                        'op' => 'and',
                        'rules' => [[
                            'op' => 'and',
                            'rules' => [[
                                'field' => 'media_type',
                                'operator' => 'eq',
                                'value' => 'image',
                            ]],
                        ]],
                    ]],
                ]],
            ]],
        ]);
    }

    public function testPredicateCountIsBounded(): void
    {
        $rules = [];
        for ($i = 0; $i < SmartCollectionRule::MAX_PREDICATES + 1; ++$i) {
            $rules[] = [
                'field' => 'media_type',
                'operator' => 'eq',
                'value' => 'image',
            ];
        }

        $this->expectException(\InvalidArgumentException::class);

        SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => $rules,
        ]);
    }

    public function testInListLengthIsBounded(): void
    {
        $values = array_fill(
            0,
            SmartCollectionRule::MAX_IN_VALUES + 1,
            'image',
        );

        $this->expectException(\InvalidArgumentException::class);

        SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'field' => 'media_type',
                'operator' => 'in',
                'value' => $values,
            ]],
        ]);
    }

    public function testManualAndSmartCollectionDomainModesRemainExplicit(): void
    {
        $manual = Collection::create(null, 'Manual');
        self::assertSame(CollectionMode::Manual, $manual->mode);
        self::assertNull($manual->smartRule);
        self::assertSame(Visibility::Private, $manual->visibility);

        $rule = SmartCollectionRule::fromArray([
            'version' => 1,
            'op' => 'and',
            'rules' => [[
                'field' => 'media_type',
                'operator' => 'eq',
                'value' => 'image',
            ]],
        ]);

        $smart = Collection::createSmart(
            Uuid::v7(),
            'Smart',
            $rule,
        );

        self::assertSame(CollectionMode::Smart, $smart->mode);
        self::assertSame($rule, $smart->smartRule);
        self::assertSame(Visibility::Private, $smart->visibility);
    }
}
