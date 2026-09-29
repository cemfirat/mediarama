<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Mediarama\Import\Application\ImportCheckpointRepository;
use Mediarama\Import\Application\ImportMappingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class CoppermineCollectionImporter
{
    private const FIRST_USER_CAT = 10000;

    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private Connection $target,
        private ImportMappingRepository $mappings,
        private ImportCheckpointRepository $checkpoints,
        private CoppermineSourceKey $sourceKey,
        private CoppermineTablePrefix $prefix,
    ) {
    }

    public function importCategories(int $batchSize = 100): int
    {
        $source = $this->sourceFactory->create();
        $cursor = (int) ($this->checkpoints->get($this->sourceKey->value(), 'categories') ?? '0');
        $table = $source->quoteIdentifier($this->prefix->table('categories'));

        $rows = $source->fetchAllAssociative(
            sprintf(
                'SELECT cid, owner_id, name, description, pos, parent FROM %s WHERE cid > :cursor ORDER BY cid ASC LIMIT %d',
                $table,
                max(1, min($batchSize, 1000)),
            ),
            ['cursor' => $cursor],
        );

        foreach ($rows as $row) {
            $sourceId = (string) $row['cid'];
            $targetId = $this->mappings->findTargetId($this->sourceKey->value(), 'category', $sourceId) ?? Uuid::v7();

            $ownerId = null;
            if ((int) $row['owner_id'] > 0) {
                $ownerId = $this->mappings->findTargetId($this->sourceKey->value(), 'user', (string) $row['owner_id']);
            }

            $this->target->executeStatement(
                <<<'SQL'
INSERT INTO collections (
    id, owner_id, parent_id, slug, title, description, visibility, position,
    created_at, updated_at
) VALUES (
    :id, :owner_id, NULL, NULL, :title, :description, 'public', :position,
    NOW(), NOW()
)
ON CONFLICT (id) DO UPDATE SET
    owner_id = EXCLUDED.owner_id,
    title = EXCLUDED.title,
    description = EXCLUDED.description,
    position = EXCLUDED.position,
    updated_at = NOW()
SQL,
                [
                    'id' => $targetId->toRfc4122(),
                    'owner_id' => $ownerId?->toRfc4122(),
                    'title' => (string) $row['name'],
                    'description' => (string) $row['description'],
                    'position' => (int) $row['pos'],
                ],
            );

            $this->mappings->remember($this->sourceKey->value(), 'category', $sourceId, $targetId);
            $this->checkpoints->save($this->sourceKey->value(), 'categories', $sourceId);
        }

        if (count($rows) < $batchSize) {
            $this->resolveCategoryParents($source);
        }

        return count($rows);
    }

    public function importAlbums(int $batchSize = 100): int
    {
        $source = $this->sourceFactory->create();
        $cursor = (int) ($this->checkpoints->get($this->sourceKey->value(), 'albums') ?? '0');
        $table = $source->quoteIdentifier($this->prefix->table('albums'));

        $rows = $source->fetchAllAssociative(
            sprintf(
                'SELECT aid, title, description, visibility, pos, category, owner, alb_hits FROM %s WHERE aid > :cursor ORDER BY aid ASC LIMIT %d',
                $table,
                max(1, min($batchSize, 1000)),
            ),
            ['cursor' => $cursor],
        );

        foreach ($rows as $row) {
            $sourceId = (string) $row['aid'];
            $targetId = $this->mappings->findTargetId($this->sourceKey->value(), 'album', $sourceId) ?? Uuid::v7();
            $ownerId = $this->mappings->findTargetId($this->sourceKey->value(), 'user', (string) $row['owner']);
            $categoryId = (int) $row['category'];
            $parentId = null;

            if ($categoryId > 0 && $categoryId < self::FIRST_USER_CAT) {
                $parentId = $this->mappings->findTargetId($this->sourceKey->value(), 'category', (string) $categoryId);
                if ($parentId === null) {
                    throw new \RuntimeException(sprintf(
                        'Coppermine album %s references category %d which has not been imported.',
                        $sourceId,
                        $categoryId,
                    ));
                }
            }

            // Categories >= FIRST_USER_CAT are Coppermine's virtual per-user gallery
            // namespace, not normal category rows. Their albums become owned root
            // collections instead of inventing synthetic source categories.

            // Coppermine visibility=0 means public. Other values encode group/user
            // restrictions and are imported conservatively until ACL conversion.
            $visibility = (int) $row['visibility'] === 0 ? 'public' : 'restricted';

            $this->target->executeStatement(
                <<<'SQL'
INSERT INTO collections (
    id, owner_id, parent_id, slug, title, description, visibility, position, view_count,
    created_at, updated_at
) VALUES (
    :id, :owner_id, :parent_id, NULL, :title, :description, :visibility, :position, :view_count,
    NOW(), NOW()
)
ON CONFLICT (id) DO UPDATE SET
    owner_id = EXCLUDED.owner_id,
    parent_id = EXCLUDED.parent_id,
    title = EXCLUDED.title,
    description = EXCLUDED.description,
    visibility = EXCLUDED.visibility,
    position = EXCLUDED.position,
    view_count = EXCLUDED.view_count,
    updated_at = NOW()
SQL,
                [
                    'id' => $targetId->toRfc4122(),
                    'owner_id' => $ownerId?->toRfc4122(),
                    'parent_id' => $parentId?->toRfc4122(),
                    'title' => (string) $row['title'],
                    'description' => (string) $row['description'],
                    'visibility' => $visibility,
                    'position' => (int) $row['pos'],
                    'view_count' => (int) $row['alb_hits'],
                ],
            );

            $this->mappings->remember($this->sourceKey->value(), 'album', $sourceId, $targetId);
            $this->checkpoints->save($this->sourceKey->value(), 'albums', $sourceId);
        }

        return count($rows);
    }

    public function importExplicitCovers(): int
    {
        $source = $this->sourceFactory->create();
        $updated = 0;

        $sources = [
            ['table' => 'categories', 'id' => 'cid', 'entity' => 'category', 'label' => 'category'],
            ['table' => 'albums', 'id' => 'aid', 'entity' => 'album', 'label' => 'album'],
        ];

        foreach ($sources as $spec) {
            $table = $source->quoteIdentifier($this->prefix->table($spec['table']));
            $idColumn = $source->quoteIdentifier($spec['id']);
            $rows = $source->fetchAllAssociative(sprintf(
                'SELECT %s AS source_id, thumb FROM %s WHERE thumb > 0 ORDER BY %s ASC',
                $idColumn,
                $table,
                $idColumn,
            ));

            foreach ($rows as $row) {
                $sourceId = (string) $row['source_id'];
                $pictureId = (string) $row['thumb'];

                $collectionId = $this->mappings->findTargetId($this->sourceKey->value(), $spec['entity'], $sourceId);
                if ($collectionId === null) {
                    throw new \RuntimeException(sprintf(
                        'Coppermine %s %s has not been imported before cover reconciliation.',
                        $spec['label'],
                        $sourceId,
                    ));
                }

                $mediaId = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', $pictureId);
                if ($mediaId === null) {
                    throw new \RuntimeException(sprintf(
                        'Coppermine %s %s explicit thumbnail references picture %s which has not been imported.',
                        $spec['label'],
                        $sourceId,
                        $pictureId,
                    ));
                }

                $affected = $this->target->update(
                    'collections',
                    [
                        'cover_media_id' => $mediaId->toRfc4122(),
                        'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ],
                    ['id' => $collectionId->toRfc4122()],
                );

                if ($affected !== 1) {
                    throw new \RuntimeException(sprintf(
                        'Mapped Coppermine %s %s points to a missing target collection.',
                        $spec['label'],
                        $sourceId,
                    ));
                }

                ++$updated;
            }
        }

        return $updated;
    }

    private function resolveCategoryParents(Connection $source): void
    {
        $table = $source->quoteIdentifier($this->prefix->table('categories'));
        $rows = $source->fetchAllAssociative(sprintf(
            'SELECT cid, parent FROM %s WHERE parent > 0 ORDER BY cid ASC',
            $table,
        ));

        foreach ($rows as $row) {
            $child = $this->mappings->findTargetId($this->sourceKey->value(), 'category', (string) $row['cid']);
            $parent = $this->mappings->findTargetId($this->sourceKey->value(), 'category', (string) $row['parent']);

            if ($child === null) {
                throw new \RuntimeException(sprintf(
                    'Coppermine category %s has not been imported before parent reconciliation.',
                    (string) $row['cid'],
                ));
            }

            if ($parent === null) {
                throw new \RuntimeException(sprintf(
                    'Coppermine category %s references parent %s which has not been imported.',
                    (string) $row['cid'],
                    (string) $row['parent'],
                ));
            }

            $this->target->update(
                'collections',
                ['parent_id' => $parent->toRfc4122(), 'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM)],
                ['id' => $child->toRfc4122()],
            );
        }
    }
}
