<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Mediarama\Import\Application\ImportMappingRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

final readonly class CoppermineKeywordImporter
{
    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private Connection $target,
        private ImportMappingRepository $mappings,
        private CoppermineSourceKey $sourceKey,
        private CoppermineTablePrefix $prefix,
    ) {
    }

    public function import(): CoppermineKeywordImportReport
    {
        $source = $this->sourceFactory->create();
        $pictures = $source->quoteIdentifier($this->prefix->table('pictures'));
        $config = $source->quoteIdentifier($this->prefix->table('config'));

        $separator = (string) ($source->fetchOne(
            'SELECT value FROM '.$config." WHERE name = 'keyword_separator'",
        ) ?: ';');

        if ($separator === '%20') {
            $separator = ' ';
        } elseif ($separator === '') {
            $separator = ';';
        }

        $rows = $source->fetchAllAssociative(
            'SELECT pid, keywords FROM '.$pictures." WHERE keywords <> '' ORDER BY pid ASC",
        );

        $slugger = new AsciiSlugger();
        $tags = 0;
        $links = 0;
        $unmapped = [];

        foreach ($rows as $row) {
            $mediaId = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', (string) $row['pid']);
            if ($mediaId === null) {
                $unmapped[] = (string) $row['pid'];
                continue;
            }

            $keywords = array_unique(array_filter(array_map(
                static fn (string $keyword): string => trim(html_entity_decode($keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                explode($separator, (string) $row['keywords']),
            )));

            foreach ($keywords as $keyword) {
                $slug = strtolower($slugger->slug($keyword)->toString());
                if ($slug === '') {
                    continue;
                }

                $tagId = $this->target->fetchOne('SELECT id FROM tags WHERE slug = :slug', ['slug' => $slug]);
                if ($tagId === false) {
                    $tagId = Uuid::v7()->toRfc4122();
                    $this->target->insert('tags', [
                        'id' => $tagId,
                        'slug' => $slug,
                        'name' => $keyword,
                        'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                        'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ]);
                    ++$tags;
                }

                $affected = $this->target->executeStatement(
                    <<<'SQL'
INSERT INTO media_tags (media_id, tag_id, source)
VALUES (:media_id, :tag_id, 'coppermine')
ON CONFLICT (media_id, tag_id) DO NOTHING
SQL,
                    ['media_id' => $mediaId->toRfc4122(), 'tag_id' => (string) $tagId],
                );
                $links += $affected;
            }
        }

        [$linkedMemberships, $unmappedAlbums, $unmappedLinkedPictures] = $this->importAlbumKeywordMemberships($source);

        $unmapped = array_values(array_unique(array_merge($unmapped, $unmappedLinkedPictures)));

        return new CoppermineKeywordImportReport(
            $tags,
            $links,
            $linkedMemberships,
            $unmapped,
            $unmappedAlbums,
            $separator,
        );
    }

    /**
     * Coppermine album keywords make pictures appear in additional albums when
     * the raw picture keyword string matches the album keyword via SQL LIKE.
     *
     * @return array{0:int,1:list<string>,2:list<string>}
     */
    private function importAlbumKeywordMemberships(Connection $source): array
    {
        $albums = $source->quoteIdentifier($this->prefix->table('albums'));
        $pictures = $source->quoteIdentifier($this->prefix->table('pictures'));

        $albumRows = $source->fetchAllAssociative(
            'SELECT aid, keyword FROM '.$albums." WHERE keyword IS NOT NULL AND keyword <> '' ORDER BY aid ASC",
        );

        $created = 0;
        $unmappedAlbums = [];
        $unmappedPictures = [];

        foreach ($albumRows as $album) {
            $sourceAlbumId = (string) $album['aid'];
            $collectionId = $this->mappings->findTargetId($this->sourceKey->value(), 'album', $sourceAlbumId);

            if ($collectionId === null) {
                $unmappedAlbums[] = $sourceAlbumId;
                continue;
            }

            $keyword = (string) $album['keyword'];
            $pictureRows = $source->fetchAllAssociative(
                'SELECT pid, position, owner_id FROM '.$pictures.' WHERE keywords LIKE :pattern ORDER BY position ASC, pid ASC',
                ['pattern' => '%'.$keyword.'%'],
            );

            foreach ($pictureRows as $picture) {
                $sourcePictureId = (string) $picture['pid'];
                $mediaId = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', $sourcePictureId);

                if ($mediaId === null) {
                    $unmappedPictures[] = $sourcePictureId;
                    continue;
                }

                $addedBy = (int) $picture['owner_id'] > 0
                    ? $this->mappings->findTargetId($this->sourceKey->value(), 'user', (string) $picture['owner_id'])
                    : null;

                $created += $this->target->executeStatement(
                    <<<'SQL'
INSERT INTO collection_media (collection_id, media_id, position, added_by, created_at)
VALUES (:collection_id, :media_id, :position, :added_by, NOW())
ON CONFLICT (collection_id, media_id) DO NOTHING
SQL,
                    [
                        'collection_id' => $collectionId->toRfc4122(),
                        'media_id' => $mediaId->toRfc4122(),
                        'position' => (int) $picture['position'],
                        'added_by' => $addedBy?->toRfc4122(),
                    ],
                );
            }
        }

        return [
            $created,
            array_values(array_unique($unmappedAlbums)),
            array_values(array_unique($unmappedPictures)),
        ];
    }
}
