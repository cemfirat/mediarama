<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Mediarama\Import\Application\ImportCheckpointRepository;
use Mediarama\Import\Application\ImportMappingRepository;
use Mediarama\Upload\Application\ContentInspector;
use Mediarama\Upload\Application\UploadContentPolicy;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Domain\StorageObjectId;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Mediarama\Media\Application\ProcessMedia;

final readonly class CoppermineMediaImporter
{
    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private Connection $target,
        private ImportMappingRepository $mappings,
        private ImportCheckpointRepository $checkpoints,
        private CoppermineSourceKey $sourceKey,
        private CoppermineTablePrefix $prefix,
        private CoppermineFileLocator $files,
        private MediaStorage $storage,
        private ContentInspector $inspector,
        private UploadContentPolicy $contentPolicy,
        private MessageBusInterface $bus,
    ) {
    }

    public function importBatch(int $batchSize = 50): CoppermineMediaImportReport
    {
        $source = $this->sourceFactory->create();
        $cursor = (int) ($this->checkpoints->get($this->sourceKey->value(), 'pictures') ?? '0');
        $table = $source->quoteIdentifier($this->prefix->table('pictures'));

        $rows = $source->fetchAllAssociative(
            sprintf(
                'SELECT pid, aid, filepath, filename, filesize, pwidth, pheight, hits, ctime, owner_id, title, caption, keywords, approved, position FROM %s WHERE pid > :cursor ORDER BY pid ASC LIMIT %d',
                $table,
                max(1, min($batchSize, 500)),
            ),
            ['cursor' => $cursor],
        );

        $imported = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $sourceId = (string) $row['pid'];
            $objectId = null;
            $targetPersisted = false;

            try {
                $existing = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', $sourceId);
                if ($existing !== null) {
                    $state = $this->target->fetchOne(
                        'SELECT processing_state FROM media_assets WHERE id = :id',
                        ['id' => $existing->toRfc4122()],
                    );

                    if ($state === false) {
                        throw new \RuntimeException('Existing picture mapping points to a missing MediaAsset.');
                    }

                    $this->target->update(
                        'media_assets',
                        ['view_count' => (int) $row['hits']],
                        ['id' => $existing->toRfc4122()],
                    );

                    if ((string) $state !== 'ready') {
                        $this->bus->dispatch(new ProcessMedia($existing->toRfc4122()));
                    }

                    ++$skipped;
                    $this->checkpoints->save($this->sourceKey->value(), 'pictures', $sourceId);
                    continue;
                }

                $collectionId = $this->mappings->findTargetId($this->sourceKey->value(), 'album', (string) $row['aid']);
                if ($collectionId === null) {
                    throw new \RuntimeException(sprintf('Album %s has not been imported.', $row['aid']));
                }

                $path = $this->files->locate((string) $row['filepath'], (string) $row['filename']);
                if (!is_file($path) || !is_readable($path)) {
                    throw new \RuntimeException('Original file is missing or unreadable: '.$path);
                }

                $mediaId = Uuid::v7();
                $storageKey = sprintf('originals/%s/%s', $mediaId->toRfc4122(), basename((string) $row['filename']));
                $objectId = new StorageObjectId('media', $storageKey);
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Unable to open Coppermine original: '.$path);
                }

                try {
                    $stored = $this->storage->write($objectId, $stream);
                } finally {
                    fclose($stream);
                }

                $inspection = $this->inspector->inspect($objectId);
                $this->contentPolicy->assertAllowed($inspection);

                $ownerId = (int) $row['owner_id'] > 0
                    ? $this->mappings->findTargetId($this->sourceKey->value(), 'user', (string) $row['owner_id'])
                    : null;

                $mediaType = $inspection->mediaType->value;

                $createdAt = (int) $row['ctime'] > 0
                    ? (new \DateTimeImmutable('@'.(int) $row['ctime']))->setTimezone(new \DateTimeZone('UTC'))
                    : new \DateTimeImmutable();

                $this->target->transactional(function () use ($row, $mediaId, $ownerId, $storageKey, $inspection, $mediaType, $createdAt, $collectionId, $sourceId): void {
                    $this->target->executeStatement(
                        <<<'SQL'
INSERT INTO media_assets (
    id, owner_id, storage_disk, storage_key, original_filename, mime_type, media_type,
    byte_size, checksum_sha256, width, height, title, description, view_count,
    processing_state, moderation_state, metadata, metadata_provenance, created_at, updated_at
) VALUES (
    :id, :owner_id, 'media', :storage_key, :filename, :mime_type, :media_type,
    :byte_size, :checksum, :width, :height, :title, :description, :view_count,
    'uploaded', :moderation_state, '{}'::jsonb, :metadata_provenance::jsonb, :created_at, NOW()
)
SQL,
                        [
                            'id' => $mediaId->toRfc4122(),
                            'owner_id' => $ownerId?->toRfc4122(),
                            'storage_key' => $storageKey,
                            'filename' => (string) $row['filename'],
                            'mime_type' => $inspection->mimeType,
                            'media_type' => $mediaType,
                            'byte_size' => $inspection->byteSize,
                            'checksum' => $inspection->sha256,
                            'width' => (int) $row['pwidth'] > 0 ? (int) $row['pwidth'] : null,
                            'height' => (int) $row['pheight'] > 0 ? (int) $row['pheight'] : null,
                            'title' => trim((string) $row['title']) !== '' ? (string) $row['title'] : null,
                            'description' => trim((string) $row['caption']) !== '' ? (string) $row['caption'] : null,
                            'view_count' => (int) $row['hits'],
                            'moderation_state' => (string) $row['approved'] === 'YES' ? 'published' : 'pending_review',
                            'metadata_provenance' => json_encode(array_filter([
                                'title' => trim((string) $row['title']) !== '' ? 'coppermine_import' : null,
                                'description' => trim((string) $row['caption']) !== '' ? 'coppermine_import' : null,
                            ]), JSON_THROW_ON_ERROR),
                            'created_at' => $createdAt->format(DATE_ATOM),
                        ],
                    );

                    $this->target->executeStatement(
                        <<<'SQL'
INSERT INTO collection_media (collection_id, media_id, position, added_by, created_at)
VALUES (:collection_id, :media_id, :position, :added_by, NOW())
ON CONFLICT (collection_id, media_id) DO NOTHING
SQL,
                        [
                            'collection_id' => $collectionId->toRfc4122(),
                            'media_id' => $mediaId->toRfc4122(),
                            'position' => (int) $row['position'],
                            'added_by' => $ownerId?->toRfc4122(),
                        ],
                    );

                    $this->mappings->remember($this->sourceKey->value(), 'picture', $sourceId, $mediaId);
                });
                $targetPersisted = true;

                $this->bus->dispatch(new ProcessMedia($mediaId->toRfc4122()));
                $this->checkpoints->save($this->sourceKey->value(), 'pictures', $sourceId);
                ++$imported;
            } catch (\Throwable $e) {
                if ($objectId instanceof StorageObjectId && !$targetPersisted && $this->storage->exists($objectId)) {
                    try {
                        $this->storage->delete($objectId);
                    } catch (\Throwable $cleanupError) {
                        $warnings[] = sprintf(
                            'Picture %s cleanup failed: %s',
                            $sourceId,
                            $cleanupError->getMessage(),
                        );
                    }
                }

                ++$skipped;
                $warnings[] = sprintf('Picture %s: %s', $sourceId, $e->getMessage());
                // Do not advance the checkpoint past a failed source row. This makes
                // the failure visible and retryable instead of silently losing media.
                break;
            }
        }

        return new CoppermineMediaImportReport($imported, $skipped, $warnings, count($rows) < $batchSize);
    }
}
