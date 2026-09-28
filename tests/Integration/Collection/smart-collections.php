<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Infrastructure\Persistence\DbalCollectionAccessPolicy;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionConfigurator;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionResolver;
use Symfony\Component\Uid\Uuid;

function requireSmart(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

/**
 * @param list<\Mediarama\Collection\Application\SmartCollectionMediaResult> $rows
 * @return list<string>
 */
function smartIds(array $rows): array
{
    return array_map(
        static fn ($row): string => $row->id->toRfc4122(),
        $rows,
    );
}

function insertSmartUser(Connection $db, Uuid $id, string $username): void
{
    $now = '2026-09-28T09:00:00+00:00';

    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => $username,
        'email' => null,
        'password_hash' => null,
        'display_name' => $username,
        'status' => 'active',
        'locale' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
}

function insertSmartCollection(
    Connection $db,
    Uuid $id,
    Uuid $owner,
    string $title,
    string $visibility = 'private',
): void {
    $now = '2026-09-28T09:00:00+00:00';

    $db->insert('collections', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'parent_id' => null,
        'cover_media_id' => null,
        'slug' => null,
        'title' => $title,
        'description' => null,
        'visibility' => $visibility,
        'position' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

function insertSmartMedia(
    Connection $db,
    Uuid $id,
    Uuid $owner,
    string $title,
    string $mediaType,
    ?string $capturedAt,
    string $createdAt,
    ?string $cameraModel = null,
    ?string $cameraMake = null,
    ?string $creator = null,
    ?string $lens = null,
    ?string $locationName = null,
    int $width = 1600,
    int $height = 1200,
): void {
    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $owner->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'smart-test/'.$id->toRfc4122().'/source',
        'original_filename' => $id->toRfc4122().'.jpg',
        'mime_type' => $mediaType === 'video' ? 'video/mp4' : 'image/jpeg',
        'media_type' => $mediaType,
        'byte_size' => 1,
        'checksum_sha256' => hash('sha256', $id->toRfc4122()),
        'width' => $width,
        'height' => $height,
        'duration_ms' => $mediaType === 'video' ? 1000 : null,
        'title' => $title,
        'description' => null,
        'captured_at' => $capturedAt,
        'processing_state' => 'ready',
        'moderation_state' => 'published',
        'metadata' => '{}',
        'creator' => $creator,
        'camera_make' => $cameraMake,
        'camera_model' => $cameraModel,
        'lens' => $lens,
        'location_name' => $locationName,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'deleted_at' => null,
    ]);
}

/** @param array<string,mixed> $rules */
function smartRule(array $rules, string $op = 'and'): SmartCollectionRule
{
    return SmartCollectionRule::fromArray([
        'version' => 1,
        'op' => $op,
        'rules' => $rules,
    ]);
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$owner = Uuid::v7();
$viewer = Uuid::v7();
$otherOwner = Uuid::v7();

$smartCollection = Uuid::v7();
$secondSmartCollection = Uuid::v7();
$manualCollection = Uuid::v7();
$publicCandidate = Uuid::v7();

$newest = Uuid::v7();
$middle = Uuid::v7();
$oldest = Uuid::v7();
$otherOwnedMatch = Uuid::v7();

$tag = Uuid::v7();

try {
    insertSmartUser($db, $owner, 'smart-owner-'.$owner->toRfc4122());
    insertSmartUser($db, $viewer, 'smart-viewer-'.$viewer->toRfc4122());
    insertSmartUser($db, $otherOwner, 'smart-other-'.$otherOwner->toRfc4122());

    insertSmartCollection($db, $smartCollection, $owner, 'Smart test');
    insertSmartCollection($db, $secondSmartCollection, $owner, 'Second Smart test');
    insertSmartCollection($db, $manualCollection, $owner, 'Manual test');
    insertSmartCollection($db, $publicCandidate, $owner, 'Public candidate', 'public');

    insertSmartMedia(
        $db,
        $newest,
        $owner,
        'Newest',
        'image',
        '2026-09-03T10:00:00+00:00',
        '2026-09-03T11:00:00+00:00',
        cameraModel: 'Nikon Z 8',
        cameraMake: 'Nikon',
        creator: 'Owner',
        lens: '35mm',
        locationName: 'Vienna',
        width: 1200,
        height: 1600,
    );
    insertSmartMedia(
        $db,
        $middle,
        $owner,
        'Middle',
        'video',
        '2026-09-02T10:00:00+00:00',
        '2026-09-02T11:00:00+00:00',
        cameraModel: 'Other Camera',
        cameraMake: 'Other',
        creator: 'Owner',
        lens: '50mm',
        locationName: 'Graz',
        width: 1920,
        height: 1080,
    );
    insertSmartMedia(
        $db,
        $oldest,
        $owner,
        'No capture date',
        'image',
        null,
        '2026-09-04T11:00:00+00:00',
        cameraModel: 'Archive Camera',
        cameraMake: 'Archive',
        creator: 'Owner',
        lens: '85mm',
        locationName: 'Vienna',
        width: 1000,
        height: 1000,
    );
    insertSmartMedia(
        $db,
        $otherOwnedMatch,
        $otherOwner,
        'Other owner match',
        'image',
        '2026-09-04T10:00:00+00:00',
        '2026-09-04T11:00:00+00:00',
        cameraModel: 'Nikon Z 8',
        cameraMake: 'Nikon',
        creator: 'Other',
        lens: '35mm',
        locationName: 'Vienna',
    );

    $db->insert('collection_media', [
        'collection_id' => $manualCollection->toRfc4122(),
        'media_id' => $newest->toRfc4122(),
        'position' => 0,
        'added_by' => $owner->toRfc4122(),
        'created_at' => '2026-09-28T09:00:00+00:00',
    ]);

    $compiler = new SmartCollectionRuleCompiler();
    $configurator = new DbalSmartCollectionConfigurator($db);
    $resolver = new DbalSmartCollectionResolver($db, $compiler);

    $cameraRule = smartRule([[
        'field' => 'camera_model',
        'operator' => 'contains',
        'value' => 'Nikon',
    ]]);

    try {
        $configurator->configureSmart($owner, $publicCandidate, $cameraRule);
        throw new RuntimeException('Expected public Smart configuration to fail.');
    } catch (SmartCollectionUnavailableException) {
        echo "OK public Collection cannot enter internal-only Smart v1 mode".PHP_EOL;
    }

    $configurator->configureSmart($owner, $smartCollection, $cameraRule);
    $configurator->configureSmart($owner, $secondSmartCollection, $cameraRule);

    $access = new DbalCollectionAccessPolicy($db);
    requireSmart(
        !$access->canAddMedia($owner, $smartCollection)
        && $access->canAddMedia($owner, $manualCollection),
        'Smart Collection is not an upload/manual-add destination',
    );

    requireSmart(
        smartIds($resolver->resolve($owner, $secondSmartCollection))
        === [$newest->toRfc4122()],
        'one MediaAsset can resolve into several Smart Collections without duplication',
    );
    requireSmart(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collection_media WHERE collection_id = :id',
            ['id' => $secondSmartCollection->toRfc4122()],
        ) === 0,
        'second Smart Collection also has no materialized membership',
    );

    $configuration = $db->fetchAssociative(
        'SELECT mode, smart_rule FROM collections WHERE id = :id',
        ['id' => $smartCollection->toRfc4122()],
    );
    requireSmart(
        $configuration !== false
        && (string) $configuration['mode'] === 'smart'
        && $configuration['smart_rule'] !== null,
        'Smart mode and validated rule are persisted',
    );
    requireSmart(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collection_media WHERE collection_id = :id',
            ['id' => $smartCollection->toRfc4122()],
        ) === 0,
        'Smart membership is not materialized into collection_media',
    );

    try {
        $db->insert('collection_media', [
            'collection_id' => $smartCollection->toRfc4122(),
            'media_id' => $newest->toRfc4122(),
            'position' => 0,
            'added_by' => $owner->toRfc4122(),
            'created_at' => '2026-09-28T09:01:00+00:00',
        ]);
        throw new RuntimeException('Expected Smart Collection membership insert to fail.');
    } catch (Doctrine\DBAL\Exception) {
        echo "OK database rejects persisted membership for Smart Collections".PHP_EOL;
    }

    try {
        $db->update(
            'collections',
            ['visibility' => 'public'],
            ['id' => $smartCollection->toRfc4122()],
        );
        throw new RuntimeException('Expected Smart Collection public visibility update to fail.');
    } catch (Doctrine\DBAL\Exception) {
        echo "OK database rejects public visibility for Smart v1".PHP_EOL;
    }

    requireSmart(
        smartIds($resolver->resolve($owner, $smartCollection, 50, 0))
        === [$newest->toRfc4122()],
        'normalized metadata rule resolves matching owner media only',
    );

    $db->update(
        'media_assets',
        ['camera_model' => 'Nikon Z 9'],
        ['id' => $middle->toRfc4122()],
    );
    requireSmart(
        smartIds($resolver->resolve($owner, $smartCollection, 50, 0))
        === [$newest->toRfc4122(), $middle->toRfc4122()],
        'metadata change updates Smart membership without membership writes',
    );

    $db->insert('tags', [
        'id' => $tag->toRfc4122(),
        'slug' => 'wedding',
        'name' => 'Wedding',
        'created_at' => '2026-09-28T09:00:00+00:00',
        'updated_at' => '2026-09-28T09:00:00+00:00',
    ]);
    $db->insert('media_tags', [
        'media_id' => $middle->toRfc4122(),
        'tag_id' => $tag->toRfc4122(),
        'source' => 'manual',
    ]);

    $configurator->configureSmart(
        $owner,
        $smartCollection,
        smartRule([[
            'field' => 'tag',
            'operator' => 'has_tag',
            'value' => 'Wedding',
        ]]),
    );
    requireSmart(
        smartIds($resolver->resolve($owner, $smartCollection))
        === [$middle->toRfc4122()],
        'tag name changes dynamic membership without duplicate rows',
    );

    $db->delete('media_tags', [
        'media_id' => $middle->toRfc4122(),
        'tag_id' => $tag->toRfc4122(),
    ]);
    requireSmart(
        $resolver->resolve($owner, $smartCollection) === [],
        'removing a tag immediately removes dynamic membership',
    );

    $db->insert('ratings', [
        'user_id' => $owner->toRfc4122(),
        'media_id' => $newest->toRfc4122(),
        'value' => 5,
        'created_at' => '2026-09-28T09:00:00+00:00',
        'updated_at' => '2026-09-28T09:00:00+00:00',
    ]);
    $db->insert('ratings', [
        'user_id' => $viewer->toRfc4122(),
        'media_id' => $newest->toRfc4122(),
        'value' => 4,
        'created_at' => '2026-09-28T09:00:00+00:00',
        'updated_at' => '2026-09-28T09:00:00+00:00',
    ]);

    $configurator->configureSmart(
        $owner,
        $smartCollection,
        smartRule([[
            'field' => 'rating_average',
            'operator' => 'gte',
            'value' => 4,
        ]]),
    );
    requireSmart(
        smartIds($resolver->resolve($owner, $smartCollection))
        === [$newest->toRfc4122()],
        'rating-average rule resolves from relational ratings',
    );

    $db->update(
        'ratings',
        ['value' => 1, 'updated_at' => '2026-09-28T10:00:00+00:00'],
        [
            'user_id' => $owner->toRfc4122(),
            'media_id' => $newest->toRfc4122(),
        ],
    );
    requireSmart(
        $resolver->resolve($owner, $smartCollection) === [],
        'rating update changes Smart membership dynamically',
    );

    $configurator->configureSmart(
        $owner,
        $smartCollection,
        smartRule([
            [
                'field' => 'media_type',
                'operator' => 'in',
                'value' => ['image', 'video'],
            ],
            [
                'op' => 'or',
                'rules' => [
                    [
                        'field' => 'orientation',
                        'operator' => 'eq',
                        'value' => 'portrait',
                    ],
                    [
                        'field' => 'location_name',
                        'operator' => 'eq',
                        'value' => 'Graz',
                    ],
                    [
                        'field' => 'orientation',
                        'operator' => 'eq',
                        'value' => 'square',
                    ],
                ],
            ],
        ]),
    );

    requireSmart(
        $resolver->count($owner, $smartCollection) === 3,
        'AND/OR, media type, orientation and coarse location compose deterministically',
    );

    $firstPage = smartIds($resolver->resolve($owner, $smartCollection, 2, 0));
    $secondPage = smartIds($resolver->resolve($owner, $smartCollection, 2, 2));
    requireSmart(
        $firstPage === [
            $newest->toRfc4122(),
            $middle->toRfc4122(),
        ]
        && $secondPage === [$oldest->toRfc4122()],
        'pagination follows capture date desc, creation date desc, UUID desc',
    );

    $configurator->configureSmart(
        $owner,
        $smartCollection,
        smartRule([
            [
                'field' => 'captured_at',
                'operator' => 'between',
                'value' => [
                    '2026-09-02T00:00:00+00:00',
                    '2026-09-03T23:59:59+00:00',
                ],
            ],
            [
                'field' => 'creator',
                'operator' => 'eq',
                'value' => 'Owner',
            ],
        ]),
    );
    requireSmart(
        smartIds($resolver->resolve($owner, $smartCollection))
        === [
            $newest->toRfc4122(),
            $middle->toRfc4122(),
        ],
        'date range and creator use normalized fields only',
    );

    try {
        $resolver->resolve($viewer, $smartCollection);
        throw new RuntimeException('Expected private Smart Collection to be unavailable to another actor.');
    } catch (SmartCollectionUnavailableException) {
        echo "OK private Smart Collection resolver enforces Collection authorization".PHP_EOL;
    }

    try {
        $configurator->configureSmart($owner, $manualCollection, $cameraRule);
        throw new RuntimeException('Expected curated manual membership conversion to fail.');
    } catch (SmartCollectionUnavailableException) {
        echo "OK curated manual membership cannot be silently converted to Smart mode".PHP_EOL;
    }

    requireSmart(
        (string) $db->fetchOne(
            'SELECT mode FROM collections WHERE id = :id',
            ['id' => $manualCollection->toRfc4122()],
        ) === 'manual'
        && (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collection_media WHERE collection_id = :id',
            ['id' => $manualCollection->toRfc4122()],
        ) === 1,
        'manual Collection membership remains unchanged',
    );

    $configurator->configureManual($owner, $smartCollection);
    $manualized = $db->fetchAssociative(
        'SELECT mode, smart_rule FROM collections WHERE id = :id',
        ['id' => $smartCollection->toRfc4122()],
    );
    requireSmart(
        $manualized !== false
        && (string) $manualized['mode'] === 'manual'
        && $manualized['smart_rule'] === null,
        'explicit conversion back to manual clears the Smart rule',
    );

    echo "Smart Collection v1 integration checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM ratings WHERE media_id IN (:a, :b, :c, :d)',
        [
            'a' => $newest->toRfc4122(),
            'b' => $middle->toRfc4122(),
            'c' => $oldest->toRfc4122(),
            'd' => $otherOwnedMatch->toRfc4122(),
        ],
    );
    $db->executeStatement(
        'DELETE FROM media_tags WHERE media_id IN (:a, :b, :c, :d)',
        [
            'a' => $newest->toRfc4122(),
            'b' => $middle->toRfc4122(),
            'c' => $oldest->toRfc4122(),
            'd' => $otherOwnedMatch->toRfc4122(),
        ],
    );
    $db->delete('tags', ['id' => $tag->toRfc4122()]);
    $db->delete('collections', ['id' => $publicCandidate->toRfc4122()]);
    $db->delete('collections', ['id' => $manualCollection->toRfc4122()]);
    $db->delete('collections', ['id' => $secondSmartCollection->toRfc4122()]);
    $db->delete('collections', ['id' => $smartCollection->toRfc4122()]);
    foreach ([$newest, $middle, $oldest, $otherOwnedMatch] as $mediaId) {
        $db->delete('media_assets', ['id' => $mediaId->toRfc4122()]);
    }
    foreach ([$owner, $viewer, $otherOwner] as $userId) {
        $db->delete('users', ['id' => $userId->toRfc4122()]);
    }
    $db->close();
}
