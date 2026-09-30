<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Collection\Infrastructure\Persistence\CollectionAccessSql;
use Mediarama\Media\Application\LibraryMediaSearch;
use Mediarama\Media\Application\LibraryMediaSearchCriteria;
use Mediarama\Media\Application\LibraryMediaSearchResult;
use Symfony\Component\Uid\Uuid;

final readonly class DbalLibraryMediaSearch implements LibraryMediaSearch
{
    public function __construct(private Connection $connection)
    {
    }

    public function search(Uuid $actorId, LibraryMediaSearchCriteria $c): array
    {
        $where = [
            AuthenticatedMediaAccessSql::predicate('m'),
        ];

        $params = ['user' => $actorId->toRfc4122()];
        $types = [];

        if ($c->text !== null && trim($c->text) !== '') {
            $where[] = "(m.search_document @@ websearch_to_tsquery('simple', :text) OR m.original_filename ILIKE :like)";
            $params['text'] = trim($c->text);
            $params['like'] = '%'.trim($c->text).'%';
        }

        foreach ([
            'creator' => $c->creator,
            'camera_make' => $c->cameraMake,
            'camera_model' => $c->cameraModel,
            'lens' => $c->lens,
        ] as $column => $value) {
            if ($value !== null && trim($value) !== '') {
                $where[] = 'm.'.$column.' ILIKE :'.$column;
                $params[$column] = '%'.trim($value).'%';
            }
        }

        if ($c->mediaType !== null) {
            $where[] = 'm.media_type = :media_type';
            $params['media_type'] = $c->mediaType;
        }

        if ($c->locationName !== null && trim($c->locationName) !== '') {
            $where[] = "POSITION(LOWER(:location_name) IN LOWER(COALESCE(m.location_name, ''))) > 0";
            $params['location_name'] = trim($c->locationName);
        }

        if ($c->tag !== null && trim($c->tag) !== '') {
            $where[] = 'EXISTS (
                SELECT 1
                FROM media_tags library_mt
                JOIN tags library_t ON library_t.id = library_mt.tag_id
                WHERE library_mt.media_id = m.id
                  AND (
                      library_t.slug = :tag
                      OR LOWER(library_t.name) = LOWER(:tag)
                  )
            )';
            $params['tag'] = trim($c->tag);
        }

        if ($c->minimumRating !== null) {
            $where[] = '(SELECT AVG(library_r.value)::double precision
                         FROM ratings library_r
                         WHERE library_r.media_id = m.id) >= :minimum_rating';
            $params['minimum_rating'] = $c->minimumRating;
        }

        if ($c->orientation !== null) {
            $where[] = match ($c->orientation) {
                'portrait' => 'm.width < m.height',
                'landscape' => 'm.width > m.height',
                'square' => 'm.width = m.height',
                default => throw new \InvalidArgumentException('Invalid orientation filter.'),
            };
        }

        if ($c->minimumIso !== null) {
            $where[] = 'm.iso >= :minimum_iso';
            $params['minimum_iso'] = $c->minimumIso;
            $types['minimum_iso'] = ParameterType::INTEGER;
        }

        if ($c->maximumIso !== null) {
            $where[] = 'm.iso <= :maximum_iso';
            $params['maximum_iso'] = $c->maximumIso;
            $types['maximum_iso'] = ParameterType::INTEGER;
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
            $where[] = '(m.latitude IS NULL OR m.longitude IS NULL)';
        }

        $params['limit'] = $c->limit;
        $params['offset'] = $c->offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT
    m.id,
    CASE WHEN m.owner_id = :user THEN 1 ELSE 0 END AS metadata_workspace_available,
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

        return array_map(static fn (array $row): LibraryMediaSearchResult => new LibraryMediaSearchResult(
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
            (int) $row['metadata_workspace_available'] === 1,
        ), $rows);
    }
}
