<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Collection\Infrastructure\Persistence\CollectionAccessSql;
use Mediarama\Media\Application\MediaSearch;
use Mediarama\Media\Application\MediaSearchCriteria;
use Mediarama\Media\Application\MediaSearchResult;
use Symfony\Component\Uid\Uuid;

final readonly class DbalMediaSearch implements MediaSearch
{
    public function __construct(private Connection $connection)
    {
    }

    public function search(Uuid $userId, MediaSearchCriteria $c): array
    {
        $where = [
            "m.deleted_at IS NULL",
            "m.processing_state = 'ready'",
            <<<'SQL'
(
    m.owner_id = :user
    OR EXISTS (
        SELECT 1
        FROM collection_media membership
        JOIN actor_visible_collections visible
          ON visible.collection_id = membership.collection_id
        JOIN collections visible_collection
          ON visible_collection.id = membership.collection_id
        WHERE membership.media_id = m.id
          AND (
              visible_collection.visibility <> 'public'
              OR visible_collection.owner_id = :user
              OR m.moderation_state = 'published'
          )
    )
)
SQL,
        ];
        $params = ['user' => $userId->toRfc4122()];
        $types = [];

        if ($c->text !== null && trim($c->text) !== '') {
            $where[] = "(m.search_document @@ websearch_to_tsquery('simple', :text) OR m.original_filename ILIKE :like)";
            $params['text'] = trim($c->text);
            $params['like'] = '%'.trim($c->text).'%';
        }
        foreach (['creator' => $c->creator, 'camera_make' => $c->cameraMake, 'camera_model' => $c->cameraModel, 'lens' => $c->lens] as $column => $value) {
            if ($value !== null && trim($value) !== '') {
                $where[] = 'm.'.$column.' ILIKE :'.$column;
                $params[$column] = '%'.trim($value).'%';
            }
        }
        if ($c->minimumIso !== null) {
            $where[] = 'm.iso >= :minimum_iso';
            $params['minimum_iso'] = $c->minimumIso;
        }
        if ($c->maximumIso !== null) {
            $where[] = 'm.iso <= :maximum_iso';
            $params['maximum_iso'] = $c->maximumIso;
        }
        if ($c->capturedFrom !== null) {
            $where[] = 'm.captured_at >= :captured_from';
            $params['captured_from'] = $c->capturedFrom->format(DATE_ATOM);
        }
        if ($c->capturedUntil !== null) {
            $where[] = 'm.captured_at <= :captured_until';
            $params['captured_until'] = $c->capturedUntil->format(DATE_ATOM);
        }
        if ($c->hasLocation === true) {
            $where[] = 'm.latitude IS NOT NULL AND m.longitude IS NOT NULL';
        } elseif ($c->hasLocation === false) {
            $where[] = 'm.latitude IS NULL OR m.longitude IS NULL';
        }

        $params['limit'] = $c->limit;
        $params['offset'] = $c->offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
             SELECT
                 m.id,
                 m.original_filename,
                 m.mime_type,
                 m.title,
                 m.description,
                 m.captured_at,
                 m.creator,
                 m.camera_make,
                 m.camera_model,
                 m.lens,
                 m.iso,
                 m.location_name
             FROM media_assets m
             WHERE '.implode(' AND ', $where).'
             ORDER BY m.captured_at DESC NULLS LAST, m.created_at DESC, m.id
             LIMIT :limit OFFSET :offset',
            $params,
            $types,
        );

        return array_map(static fn (array $row): MediaSearchResult => new MediaSearchResult(
            Uuid::fromString((string) $row['id']),
            (string) $row['original_filename'],
            (string) $row['mime_type'],
            $row['title'] !== null ? (string) $row['title'] : null,
            $row['description'] !== null ? (string) $row['description'] : null,
            $row['captured_at'] !== null ? new DateTimeImmutable((string) $row['captured_at']) : null,
            $row['creator'] !== null ? (string) $row['creator'] : null,
            $row['camera_make'] !== null ? (string) $row['camera_make'] : null,
            $row['camera_model'] !== null ? (string) $row['camera_model'] : null,
            $row['lens'] !== null ? (string) $row['lens'] : null,
            $row['iso'] !== null ? (int) $row['iso'] : null,
            $row['location_name'] !== null ? (string) $row['location_name'] : null,
        ), $rows);
    }
}
