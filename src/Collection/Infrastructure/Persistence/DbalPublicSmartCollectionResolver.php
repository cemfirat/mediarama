<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Collection\Application\PublicMediaResult;
use Mediarama\Collection\Application\PublicSmartCollectionCoverResult;
use Mediarama\Collection\Application\PublicSmartCollectionResolver;
use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Symfony\Component\Uid\Uuid;

final readonly class DbalPublicSmartCollectionResolver implements PublicSmartCollectionResolver
{
    public function __construct(
        private Connection $connection,
        private SmartCollectionRuleCompiler $compiler,
    ) {
    }

    public function count(Uuid $collectionId): int
    {
        [$ownerId, $rule] = $this->configuration($collectionId);
        $predicate = $this->compiler->compile($rule);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM media_assets m
             WHERE m.owner_id = :collection_owner
               AND '.$this->publicMediaPredicate('m').'
               AND '.$predicate->sql,
            [
                'collection_owner' => $ownerId->toRfc4122(),
                ...$predicate->parameters,
            ],
        );
    }

    public function media(
        Uuid $collectionId,
        int $limit = 120,
        int $offset = 0,
    ): array {
        if ($limit < 1 || $limit > 240 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid public Smart Collection pagination.');
        }

        [$ownerId, $rule] = $this->configuration($collectionId);
        $predicate = $this->compiler->compile($rule);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                m.id,
                m.title,
                m.description,
                m.mime_type,
                m.media_type,
                m.width,
                m.height,
                ROW_NUMBER() OVER (
                    ORDER BY
                        m.captured_at DESC NULLS LAST,
                        m.created_at DESC,
                        m.id DESC
                ) - 1 AS dynamic_position,
                thumbnail.processing_version AS thumbnail_version,
                preview.processing_version AS preview_version
             FROM media_assets m
             LEFT JOIN LATERAL (
                 SELECT d.processing_version
                 FROM media_derivatives d
                 WHERE d.media_id = m.id
                   AND d.kind = \'image\'
                   AND d.profile = \'thumbnail\'
                 ORDER BY d.processing_version DESC
                 LIMIT 1
             ) thumbnail ON TRUE
             LEFT JOIN LATERAL (
                 SELECT d.processing_version
                 FROM media_derivatives d
                 WHERE d.media_id = m.id
                   AND d.kind = \'image\'
                   AND d.profile = \'preview\'
                 ORDER BY d.processing_version DESC
                 LIMIT 1
             ) preview ON TRUE
             WHERE m.owner_id = :collection_owner
               AND '.$this->publicMediaPredicate('m').'
               AND '.$predicate->sql.'
             ORDER BY
                 m.captured_at DESC NULLS LAST,
                 m.created_at DESC,
                 m.id DESC
             LIMIT :limit OFFSET :offset',
            [
                'collection_owner' => $ownerId->toRfc4122(),
                ...$predicate->parameters,
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );

        return array_map(
            static fn (array $row): PublicMediaResult => new PublicMediaResult(
                Uuid::fromString((string) $row['id']),
                $row['title'] !== null ? (string) $row['title'] : null,
                $row['description'] !== null ? (string) $row['description'] : null,
                (string) $row['mime_type'],
                (string) $row['media_type'],
                $row['width'] !== null ? (int) $row['width'] : null,
                $row['height'] !== null ? (int) $row['height'] : null,
                (int) $row['dynamic_position'],
                $row['thumbnail_version'] !== null
                    ? (int) $row['thumbnail_version']
                    : null,
                $row['preview_version'] !== null
                    ? (int) $row['preview_version']
                    : null,
            ),
            $rows,
        );
    }

    public function cover(Uuid $collectionId): ?PublicSmartCollectionCoverResult
    {
        [$ownerId, $rule, $preferredCover] = $this->configuration($collectionId);
        $predicate = $this->compiler->compile($rule);

        $row = $this->connection->fetchAssociative(
            'SELECT
                m.id,
                thumbnail.processing_version
             FROM media_assets m
             JOIN LATERAL (
                 SELECT d.processing_version
                 FROM media_derivatives d
                 WHERE d.media_id = m.id
                   AND d.kind = \'image\'
                   AND d.profile = \'thumbnail\'
                 ORDER BY d.processing_version DESC
                 LIMIT 1
             ) thumbnail ON TRUE
             WHERE m.owner_id = :collection_owner
               AND m.media_type = \'image\'
               AND '.$this->publicMediaPredicate('m').'
               AND '.$predicate->sql.'
             ORDER BY
                 (m.id = :preferred_cover) DESC,
                 m.captured_at DESC NULLS LAST,
                 m.created_at DESC,
                 m.id DESC
             LIMIT 1',
            [
                'collection_owner' => $ownerId->toRfc4122(),
                'preferred_cover' => $preferredCover?->toRfc4122()
                    ?? '00000000-0000-0000-0000-000000000000',
                ...$predicate->parameters,
            ],
        );

        if ($row === false) {
            return null;
        }

        return new PublicSmartCollectionCoverResult(
            Uuid::fromString((string) $row['id']),
            (int) $row['processing_version'],
        );
    }

    /**
     * @return array{0:Uuid,1:SmartCollectionRule,2:?Uuid}
     */
    private function configuration(Uuid $collectionId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT c.owner_id, c.smart_rule, c.cover_media_id
FROM collections c
JOIN effective_public_collections visible
  ON visible.collection_id = c.id
WHERE c.id = :collection
  AND c.mode = 'smart'
  AND c.visibility = 'public'
  AND c.deleted_at IS NULL
SQL,
            ['collection' => $collectionId->toRfc4122()],
        );

        if (
            $row === false
            || $row['owner_id'] === null
            || $row['smart_rule'] === null
        ) {
            throw new \DomainException('Public Smart Collection is unavailable.');
        }

        $rawRule = $row['smart_rule'];
        if (is_string($rawRule)) {
            $rawRule = json_decode(
                $rawRule,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        if (!is_array($rawRule)) {
            throw new \DomainException('Public Smart Collection rule is invalid.');
        }

        return [
            Uuid::fromString((string) $row['owner_id']),
            SmartCollectionRule::fromArray($rawRule),
            $row['cover_media_id'] !== null
                ? Uuid::fromString((string) $row['cover_media_id'])
                : null,
        ];
    }

    private function publicMediaPredicate(string $alias): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid media SQL alias.');
        }

        return <<<SQL
{$alias}.deleted_at IS NULL
AND {$alias}.processing_state = 'ready'
AND {$alias}.moderation_state = 'published'
AND EXISTS (
    SELECT 1
    FROM collection_media public_membership
    JOIN effective_public_collections public_collection
      ON public_collection.collection_id = public_membership.collection_id
    WHERE public_membership.media_id = {$alias}.id
)
SQL;
    }
}
