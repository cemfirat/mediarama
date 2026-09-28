<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Publishing\Domain\PublicationOrigin;
use Mediarama\Publishing\Infrastructure\Persistence\DbalPublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

function requirePublicationTimeline(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function sameInstant(?DateTimeImmutable $actual, DateTimeImmutable $expected): bool
{
    return $actual !== null && $actual->getTimestamp() === $expected->getTimestamp();
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$store = new DbalPublicPublicationTimelineStore($db);

$mediaId = Uuid::v7();
$collectionId = Uuid::v7();
$importedCollectionId = Uuid::v7();

$recordCreated = new DateTimeImmutable('2020-01-02T03:04:05+00:00');
$firstPublished = new DateTimeImmutable('2024-04-05T06:07:08+00:00');
$republished = new DateTimeImmutable('2025-05-06T07:08:09+00:00');
$publicChanged = new DateTimeImmutable('2025-05-07T08:09:10+00:00');
$olderPublicChange = new DateTimeImmutable('2025-05-06T08:09:10+00:00');
$importedPublished = new DateTimeImmutable('2018-08-09T10:11:12+00:00');

try {
    $db->insert('media_assets', [
        'id' => $mediaId->toRfc4122(),
        'owner_id' => null,
        'storage_disk' => 'media',
        'storage_key' => 'publication-timeline/'.$mediaId->toRfc4122().'/source',
        'original_filename' => 'publication-timeline.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => str_repeat('a', 64),
        'width' => 1,
        'height' => 1,
        'duration_ms' => null,
        'title' => 'Publication timeline media',
        'description' => 'Public presentation text',
        'captured_at' => null,
        'processing_state' => 'ready',
        'moderation_state' => 'published',
        'metadata' => '{}',
        'created_at' => $recordCreated->format(DATE_ATOM),
        'updated_at' => $recordCreated->format(DATE_ATOM),
        'deleted_at' => null,
    ]);

    foreach ([
        [$collectionId, 'Publication timeline collection'],
        [$importedCollectionId, 'Imported publication collection'],
    ] as [$id, $title]) {
        $db->insert('collections', [
            'id' => $id->toRfc4122(),
            'owner_id' => null,
            'parent_id' => null,
            'cover_media_id' => null,
            'slug' => null,
            'title' => $title,
            'description' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => $recordCreated->format(DATE_ATOM),
            'updated_at' => $recordCreated->format(DATE_ATOM),
            'deleted_at' => null,
        ]);
    }

    $initialMedia = $store->media($mediaId);
    requirePublicationTimeline(
        $initialMedia->publishedAt === null
        && $initialMedia->updatedAt === null
        && $initialMedia->origin === null
        && $initialMedia->source === null,
        'record creation time is not treated as publication time',
    );

    $db->executeStatement(
        "UPDATE media_assets
         SET metadata = '{\"internal_note\":\"not public\"}'::jsonb,
             updated_at = :updated
         WHERE id = :id",
        [
            'updated' => (new DateTimeImmutable('2023-03-04T05:06:07+00:00'))->format(DATE_ATOM),
            'id' => $mediaId->toRfc4122(),
        ],
    );
    $afterInternalEdit = $store->media($mediaId);
    requirePublicationTimeline(
        $afterInternalEdit->publishedAt === null
        && $afterInternalEdit->updatedAt === null,
        'internal metadata edits do not fabricate public timeline values',
    );

    $publishedMedia = $store->recordMediaFirstPublication(
        $mediaId,
        $firstPublished,
        PublicationOrigin::Editorial,
    );
    requirePublicationTimeline(
        sameInstant($publishedMedia->publishedAt, $firstPublished)
        && sameInstant($publishedMedia->updatedAt, $firstPublished)
        && $publishedMedia->origin === PublicationOrigin::Editorial
        && $publishedMedia->source === null,
        'first editorial publication is recorded with explicit provenance',
    );

    $db->update('media_assets', ['moderation_state' => 'draft'], ['id' => $mediaId->toRfc4122()]);
    $db->update('media_assets', ['moderation_state' => 'published'], ['id' => $mediaId->toRfc4122()]);

    $republishedMedia = $store->recordMediaFirstPublication(
        $mediaId,
        $republished,
        PublicationOrigin::Editorial,
    );
    requirePublicationTimeline(
        sameInstant($republishedMedia->publishedAt, $firstPublished)
        && sameInstant($republishedMedia->updatedAt, $firstPublished),
        'unpublish and republish do not rewrite first publication or public update time',
    );

    $changedMedia = $store->touchMediaPublicContent($mediaId, $publicChanged);
    requirePublicationTimeline(
        sameInstant($changedMedia->publishedAt, $firstPublished)
        && sameInstant($changedMedia->updatedAt, $publicChanged),
        'meaningful public MediaAsset content change advances public update time',
    );

    $monotonicMedia = $store->touchMediaPublicContent($mediaId, $olderPublicChange);
    requirePublicationTimeline(
        sameInstant($monotonicMedia->updatedAt, $publicChanged),
        'public update time is monotonic and cannot move backwards',
    );

    $publishedCollection = $store->recordCollectionFirstPublication(
        $collectionId,
        $firstPublished,
    );
    $changedCollection = $store->touchCollectionPublicContent(
        $collectionId,
        $publicChanged,
    );
    requirePublicationTimeline(
        sameInstant($publishedCollection->publishedAt, $firstPublished)
        && sameInstant($changedCollection->updatedAt, $publicChanged),
        'Collection publication and public-content update timelines are independent explicit events',
    );

    $imported = $store->recordCollectionFirstPublication(
        $importedCollectionId,
        $importedPublished,
        PublicationOrigin::Imported,
        'legacy-cms:fixture-source',
    );
    requirePublicationTimeline(
        sameInstant($imported->publishedAt, $importedPublished)
        && $imported->origin === PublicationOrigin::Imported
        && $imported->source === 'legacy-cms:fixture-source',
        'trustworthy imported publication date preserves source provenance',
    );

    try {
        $store->recordCollectionFirstPublication(
            Uuid::v7(),
            $importedPublished,
            PublicationOrigin::Imported,
            null,
        );
        throw new RuntimeException('Expected missing imported source validation failure.');
    } catch (InvalidArgumentException) {
        echo "OK imported publication timestamps require explicit source provenance".PHP_EOL;
    }

    requirePublicationTimeline(
        (string) $db->fetchOne(
            'SELECT public_published_at FROM media_assets WHERE id = :id',
            ['id' => $mediaId->toRfc4122()],
        ) !== $recordCreated->format('Y-m-d H:i:sP'),
        'database never aliases created_at to first publication time',
    );
} finally {
    $db->delete('collections', ['id' => $collectionId->toRfc4122()]);
    $db->delete('collections', ['id' => $importedCollectionId->toRfc4122()]);
    $db->delete('media_assets', ['id' => $mediaId->toRfc4122()]);
    $db->close();
}
