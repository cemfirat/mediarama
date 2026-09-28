<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use DateTimeImmutable;
use DateTimeZone;
use Mediarama\Organization\Domain\OrganizationEvidence;
use Mediarama\Organization\Domain\OrganizationEvidenceSource;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalType;
use Symfony\Component\Uid\Uuid;

final class DeterministicOrganizationPlanner
{
    public const MIN_SUPPORT = 5;
    public const MAX_PROPOSALS = 20;

    private const MAX_GROUPS_PER_DIMENSION = 5;

    /**
     * @param list<OrganizationMetadataRecord> $records
     * @return list<OrganizationProposalCandidate>
     */
    public function plan(array $records): array
    {
        if (count($records) < self::MIN_SUPPORT) {
            return [];
        }

        /** @var list<array{priority:int,key:string,candidate:OrganizationProposalCandidate}> $entries */
        $entries = [];

        $this->appendLocationMonth($entries, $records);
        $this->appendTagGroups($entries, $records);
        $this->appendHighRated($entries, $records);
        $this->appendMediaTypes($entries, $records);
        $this->appendTextGroups(
            $entries,
            $records,
            priority: 50,
            keyPrefix: 'camera',
            field: 'camera_model',
            titlePrefix: 'Camera',
            value: static fn (OrganizationMetadataRecord $record): ?string => $record->cameraModel,
        );
        $this->appendTextGroups(
            $entries,
            $records,
            priority: 60,
            keyPrefix: 'lens',
            field: 'lens',
            titlePrefix: 'Lens',
            value: static fn (OrganizationMetadataRecord $record): ?string => $record->lens,
        );
        $this->appendNeedsReview($entries, $records);

        usort(
            $entries,
            static function (array $left, array $right): int {
                $priority = $left['priority'] <=> $right['priority'];
                if ($priority !== 0) {
                    return $priority;
                }

                $support = $right['candidate']->support()
                    <=> $left['candidate']->support();
                if ($support !== 0) {
                    return $support;
                }

                return strcmp($left['key'], $right['key']);
            },
        );

        $selected = [];
        $seenMediaSets = [];

        foreach ($entries as $entry) {
            $candidate = $entry['candidate'];
            $mediaSet = array_map(
                static fn (Uuid $id): string => $id->toRfc4122(),
                $candidate->affectedMediaIds,
            );
            sort($mediaSet);
            $signature = implode('|', $mediaSet);

            if (isset($seenMediaSets[$signature])) {
                continue;
            }

            $seenMediaSets[$signature] = true;
            $selected[] = $candidate;

            if (count($selected) >= self::MAX_PROPOSALS) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param list<array{priority:int,key:string,candidate:OrganizationProposalCandidate}> $entries
     * @param list<OrganizationMetadataRecord> $records
     */
    private function appendLocationMonth(array &$entries, array $records): void
    {
        /** @var array<string,array{label:string,month:string,records:list<OrganizationMetadataRecord>}> $groups */
        $groups = [];

        foreach ($records as $record) {
            $location = $this->usableLabel($record->locationName);
            if ($location === null || $record->capturedAt === null) {
                continue;
            }

            $month = $record->capturedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m');
            $key = strtolower($location).'|'.$month;

            $groups[$key] ??= [
                'label' => $location,
                'month' => $month,
                'records' => [],
            ];
            $groups[$key]['records'][] = $record;
        }

        foreach ($this->topGroups($groups) as $key => $group) {
            $start = new DateTimeImmutable(
                $group['month'].'-01T00:00:00+00:00',
            );
            $until = $start
                ->modify('first day of next month')
                ->modify('-1 second');
            $support = count($group['records']);
            $title = $group['label'].' · '.$group['month'];

            $entries[] = [
                'priority' => 10,
                'key' => 'location-month:'.$key,
                'candidate' => $this->smartCandidate(
                    title: $title,
                    description: 'Dynamic coarse-location and capture-month cluster.',
                    rules: [
                        [
                            'field' => 'location_name',
                            'operator' => 'eq',
                            'value' => $group['label'],
                        ],
                        [
                            'field' => 'captured_at',
                            'operator' => 'between',
                            'value' => [
                                $start->format(DATE_ATOM),
                                $until->format(DATE_ATOM),
                            ],
                        ],
                    ],
                    rationale: sprintf(
                        '%d selected MediaAssets share the coarse location "%s" and capture month %s.',
                        $support,
                        $group['label'],
                        $group['month'],
                    ),
                    records: $group['records'],
                    evidence: [
                        sprintf(
                            '%d MediaAssets share the normalized coarse location "%s".',
                            $support,
                            $group['label'],
                        ),
                        sprintf(
                            '%d MediaAssets were captured during %s.',
                            $support,
                            $group['month'],
                        ),
                    ],
                ),
            ];
        }
    }

    /**
     * @param list<array{priority:int,key:string,candidate:OrganizationProposalCandidate}> $entries
     * @param list<OrganizationMetadataRecord> $records
     */
    private function appendTagGroups(array &$entries, array $records): void
    {
        /** @var array<string,array{label:string,records:list<OrganizationMetadataRecord>}> $groups */
        $groups = [];

        foreach ($records as $record) {
            $seen = [];
            foreach ($record->tags as $rawTag) {
                $tag = $this->usableLabel($rawTag);
                if ($tag === null) {
                    continue;
                }

                $key = strtolower($tag);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $groups[$key] ??= [
                    'label' => $tag,
                    'records' => [],
                ];
                $groups[$key]['records'][] = $record;
            }
        }

        foreach ($this->topGroups($groups) as $key => $group) {
            $support = count($group['records']);

            $entries[] = [
                'priority' => 20,
                'key' => 'tag:'.$key,
                'candidate' => $this->smartCandidate(
                    title: 'Tag · '.$group['label'],
                    description: 'Dynamic group based on an existing normalized tag.',
                    rules: [[
                        'field' => 'tag',
                        'operator' => 'has_tag',
                        'value' => $group['label'],
                    ]],
                    rationale: sprintf(
                        '%d selected MediaAssets already share the normalized tag "%s".',
                        $support,
                        $group['label'],
                    ),
                    records: $group['records'],
                    evidence: [sprintf(
                        '%d MediaAssets share the existing normalized tag "%s".',
                        $support,
                        $group['label'],
                    )],
                ),
            ];
        }
    }

    /**
     * @param list<array{priority:int,key:string,candidate:OrganizationProposalCandidate}> $entries
     * @param list<OrganizationMetadataRecord> $records
     */
    private function appendHighRated(array &$entries, array $records): void
    {
        $matches = array_values(array_filter(
            $records,
            static fn (OrganizationMetadataRecord $record): bool =>
                $record->ratingAverage !== null
                && $record->ratingAverage >= 4.0,
        ));

        if (count($matches) < self::MIN_SUPPORT) {
            return;
        }

        $support = count($matches);
        $entries[] = [
            'priority' => 30,
            'key' => 'rating:4',
            'candidate' => $this->smartCandidate(
                title: 'Highly rated',
                description: 'Dynamic selection with an average rating of at least 4.',
                rules: [[
                    'field' => 'rating_average',
                    'operator' => 'gte',
                    'value' => 4.0,
                ]],
                rationale: sprintf(
                    '%d selected MediaAssets currently have an average rating of at least 4.',
                    $support,
                ),
                records: $matches,
                evidence: [sprintf(
                    '%d MediaAssets meet the normalized rating threshold >= 4.',
                    $support,
                )],
            ),
        ];
    }

    /**
     * @param list<array{priority:int,key:string,candidate:OrganizationProposalCandidate}> $entries
     * @param list<OrganizationMetadataRecord> $records
     */
    private function appendMediaTypes(array &$entries, array $records): void
    {
        /** @var array<string,array{label:string,records:list<OrganizationMetadataRecord>}> $groups */
        $groups = [];

        foreach ($records as $record) {
            $groups[$record->mediaType] ??= [
                'label' => $record->mediaType,
                'records' => [],
            ];
            $groups[$record->mediaType]['records'][] = $record;
        }

        if (count($groups) < 2) {
            return;
        }

        foreach ($this->topGroups($groups) as $key => $group) {
            $support = count($group['records']);
            $title = match ($group['label']) {
                'image' => 'Images',
                'video' => 'Videos',
                'audio' => 'Audio',
                'document' => 'Documents',
                default => ucfirst($group['label']),
            };

            $entries[] = [
                'priority' => 40,
                'key' => 'media-type:'.$key,
                'candidate' => $this->smartCandidate(
                    title: $title,
                    description: 'Dynamic selection by normalized media type.',
                    rules: [[
                        'field' => 'media_type',
                        'operator' => 'eq',
                        'value' => $group['label'],
                    ]],
                    rationale: sprintf(
                        '%d selected MediaAssets share media type "%s".',
                        $support,
                        $group['label'],
                    ),
                    records: $group['records'],
                    evidence: [sprintf(
                        '%d MediaAssets have normalized media type "%s".',
                        $support,
                        $group['label'],
                    )],
                ),
            ];
        }
    }

    /**
     * @param list<array{priority:int,key:string,candidate:OrganizationProposalCandidate}> $entries
     * @param list<OrganizationMetadataRecord> $records
     * @param callable(OrganizationMetadataRecord):?string $value
     */
    private function appendTextGroups(
        array &$entries,
        array $records,
        int $priority,
        string $keyPrefix,
        string $field,
        string $titlePrefix,
        callable $value,
    ): void {
        /** @var array<string,array{label:string,records:list<OrganizationMetadataRecord>}> $groups */
        $groups = [];

        foreach ($records as $record) {
            $label = $this->usableLabel($value($record));
            if ($label === null) {
                continue;
            }

            $key = strtolower($label);
            $groups[$key] ??= [
                'label' => $label,
                'records' => [],
            ];
            $groups[$key]['records'][] = $record;
        }

        foreach ($this->topGroups($groups) as $key => $group) {
            $support = count($group['records']);

            $entries[] = [
                'priority' => $priority,
                'key' => $keyPrefix.':'.$key,
                'candidate' => $this->smartCandidate(
                    title: $titlePrefix.' · '.$group['label'],
                    description: 'Dynamic metadata-based organization suggestion.',
                    rules: [[
                        'field' => $field,
                        'operator' => 'eq',
                        'value' => $group['label'],
                    ]],
                    rationale: sprintf(
                        '%d selected MediaAssets share %s "%s".',
                        $support,
                        strtolower($titlePrefix),
                        $group['label'],
                    ),
                    records: $group['records'],
                    evidence: [sprintf(
                        '%d MediaAssets share normalized %s "%s".',
                        $support,
                        strtolower($titlePrefix),
                        $group['label'],
                    )],
                ),
            ];
        }
    }

    /**
     * @param list<array{priority:int,key:string,candidate:OrganizationProposalCandidate}> $entries
     * @param list<OrganizationMetadataRecord> $records
     */
    private function appendNeedsReview(array &$entries, array $records): void
    {
        $matches = array_values(array_filter(
            $records,
            static fn (OrganizationMetadataRecord $record): bool =>
                $record->tags === []
                && !$record->hasCollectionMembership,
        ));

        if (count($matches) < self::MIN_SUPPORT) {
            return;
        }

        $support = count($matches);
        $payload = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::ReviewBucket,
            [
                'version' => OrganizationProposalPayload::VERSION,
                'title' => 'Needs review',
                'description' => 'Selected media with no normalized tags or Collection membership.',
            ],
        );

        $entries[] = [
            'priority' => 70,
            'key' => 'review:unclassified',
            'candidate' => new OrganizationProposalCandidate(
                $payload,
                sprintf(
                    '%d selected MediaAssets currently have neither normalized tags nor Collection membership.',
                    $support,
                ),
                $this->mediaIds($matches),
                [new OrganizationEvidence(
                    OrganizationEvidenceSource::Metadata,
                    sprintf(
                        '%d MediaAssets are currently untagged and outside Collection membership.',
                        $support,
                    ),
                )],
            ),
        ];
    }

    /**
     * @param list<array{label:string,records:list<OrganizationMetadataRecord>}>
     *     |array<string,array{label:string,month:string,records:list<OrganizationMetadataRecord>}> $groups
     * @return array<string,array<string,mixed>>
     */
    private function topGroups(array $groups): array
    {
        $groups = array_filter(
            $groups,
            static fn (array $group): bool =>
                count($group['records']) >= self::MIN_SUPPORT,
        );

        uasort(
            $groups,
            static function (array $left, array $right): int {
                $support = count($right['records']) <=> count($left['records']);
                if ($support !== 0) {
                    return $support;
                }

                return strcasecmp(
                    (string) $left['label'],
                    (string) $right['label'],
                );
            },
        );

        return array_slice(
            $groups,
            0,
            self::MAX_GROUPS_PER_DIMENSION,
            true,
        );
    }

    /**
     * @param list<array{field:string,operator:string,value:mixed}> $rules
     * @param list<OrganizationMetadataRecord> $records
     * @param list<string> $evidence
     */
    private function smartCandidate(
        string $title,
        string $description,
        array $rules,
        string $rationale,
        array $records,
        array $evidence,
    ): OrganizationProposalCandidate {
        $payload = OrganizationProposalPayload::fromArray(
            OrganizationProposalType::SmartCollection,
            [
                'version' => OrganizationProposalPayload::VERSION,
                'title' => $title,
                'description' => $description,
                'rule' => [
                    'version' => 1,
                    'op' => 'and',
                    'rules' => $rules,
                ],
            ],
        );

        return new OrganizationProposalCandidate(
            $payload,
            $rationale,
            $this->mediaIds($records),
            array_map(
                static fn (string $summary): OrganizationEvidence =>
                    new OrganizationEvidence(
                        OrganizationEvidenceSource::Metadata,
                        $summary,
                    ),
                $evidence,
            ),
        );
    }

    /**
     * @param list<OrganizationMetadataRecord> $records
     * @return list<Uuid>
     */
    private function mediaIds(array $records): array
    {
        $ids = array_map(
            static fn (OrganizationMetadataRecord $record): Uuid => $record->id,
            $records,
        );

        usort(
            $ids,
            static fn (Uuid $left, Uuid $right): int =>
                strcmp($left->toRfc4122(), $right->toRfc4122()),
        );

        return $ids;
    }

    private function usableLabel(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $length = iconv_strlen($value, 'UTF-8');
        if ($length === false || $length > 160) {
            return null;
        }

        return $value;
    }
}
