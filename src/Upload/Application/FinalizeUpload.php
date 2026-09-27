<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\ProcessMedia;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Application\ValidateStoredMediaStructure;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class FinalizeUpload
{
    public function __construct(
        private UploadSessionRepository $sessions,
        private MediaAssetRepository $media,
        private MediaStorage $storage,
        private ContentInspector $inspector,
        private UploadContentPolicy $contentPolicy,
        private ValidateStoredMediaStructure $structureValidator,
        private UploadDestinationAuthorizer $authorizer,
        private UploadFinalizationRepository $finalizations,
        private UploadFinalizationCriticalSection $criticalSection,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(Uuid $sessionId, Uuid $actingUserId): MediaAsset
    {
        $session = $this->sessions->get($sessionId);
        $this->assertOwner($session, $actingUserId);

        $existing = $this->existingAsset($sessionId);
        if ($existing !== null) {
            if ($session->status === UploadStatus::Finalizing) {
                $this->criticalSection->run(
                    $sessionId,
                    function () use ($sessionId, $actingUserId): void {
                        $locked = $this->sessions->get($sessionId);
                        $this->assertOwner($locked, $actingUserId);

                        if (
                            $locked->status === UploadStatus::Finalizing
                            && $this->existingAsset($sessionId) !== null
                        ) {
                            // Repair the pre-hardening crash window where the
                            // mapping existed but the session was not completed.
                            $locked->complete();
                            $this->sessions->save($locked);
                            $this->bus->dispatch(new ProcessMedia($sessionId->toRfc4122()));
                        }
                    },
                );
            }

            return $existing;
        }

        // New upload-created media deliberately reuses the upload-session UUID.
        // This makes the immutable target deterministic across concurrent calls
        // and crash retries without introducing a separate reservation table.
        $mediaId = $sessionId;
        $temporary = new StorageObjectId('media', $session->temporaryStorageKey);
        $permanent = new StorageObjectId(
            'media',
            sprintf('originals/%s/source', $mediaId->toRfc4122()),
        );

        $content = null;

        if ($session->status === UploadStatus::Uploaded) {
            if ($session->isExpired()) {
                throw new \DomainException('Upload session has expired.');
            }

            // Keep all expensive work outside the database row lock.
            $this->authorizer->assertCanUpload($actingUserId, $session->targetCollectionId);
            $content = $this->inspectAndValidate($temporary, $session);

            $existing = $this->criticalSection->run(
                $sessionId,
                function () use ($sessionId, $actingUserId): ?MediaAsset {
                    $locked = $this->sessions->get($sessionId);
                    $this->assertOwner($locked, $actingUserId);

                    $existing = $this->existingAsset($sessionId);
                    if ($existing !== null) {
                        return $existing;
                    }

                    if ($locked->status === UploadStatus::Uploaded) {
                        if ($locked->isExpired()) {
                            throw new \DomainException('Upload session has expired.');
                        }

                        // Re-check authorization immediately before claiming the
                        // finalization state because validation may take time.
                        $this->authorizer->assertCanUpload(
                            $actingUserId,
                            $locked->targetCollectionId,
                        );

                        $locked->beginFinalization();
                        $this->sessions->save($locked);

                        return null;
                    }

                    if ($locked->status === UploadStatus::Finalizing) {
                        return null;
                    }

                    throw new \DomainException(
                        'Upload session cannot enter finalization from its current state.',
                    );
                },
            );

            if ($existing !== null) {
                return $existing;
            }
        } elseif ($session->status !== UploadStatus::Finalizing) {
            throw new \DomainException(
                'Upload session cannot be finalized from its current state.',
            );
        }

        // A retry after successful promotion may no longer have the temporary
        // object. Because media identity is deterministic, the permanent object
        // is the recovery source in that case.
        $validationObject = $temporary;
        if (!$this->storage->exists($validationObject)) {
            $validationObject = $permanent;
            $content = null;
        }

        if (!$this->storage->exists($validationObject)) {
            throw new \DomainException(
                'Upload finalization cannot recover because neither temporary nor permanent media exists.',
            );
        }

        if ($content === null) {
            $content = $this->inspectAndValidate($validationObject, $session);
        }

        // LocalMediaStorage promotion is idempotent for the deterministic target.
        // A concurrent winner or a previous crashed request may already have moved it.
        $stored = $this->storage->promote($temporary, $permanent);
        $this->assertStoredObjectMatchesInspection($stored, $content);

        return $this->criticalSection->run(
            $sessionId,
            function () use (
                $sessionId,
                $actingUserId,
                $mediaId,
                $permanent,
                $content,
                $stored,
            ): MediaAsset {
                $locked = $this->sessions->get($sessionId);
                $this->assertOwner($locked, $actingUserId);

                $existing = $this->existingAsset($sessionId);
                if ($existing !== null) {
                    return $existing;
                }

                if ($locked->status === UploadStatus::Uploaded) {
                    // Defensive recovery if a custom transaction implementation
                    // rolled back the claim while storage promotion succeeded.
                    $locked->beginFinalization();
                } elseif ($locked->status !== UploadStatus::Finalizing) {
                    throw new \DomainException(
                        'Upload session cannot complete finalization from its current state.',
                    );
                }

                $asset = MediaAsset::createWithId(
                    $mediaId,
                    $actingUserId,
                    $permanent,
                    $locked->originalFilename,
                    $content->mimeType,
                    $content->mediaType,
                    $stored->byteSize,
                    $content->sha256,
                );

                $this->media->save($asset);
                $this->finalizations->remember($sessionId, $asset->id);
                $locked->complete();
                $this->sessions->save($locked);

                // The default production transport is Doctrine on the same
                // PostgreSQL connection. CI verifies that this enqueue joins
                // the surrounding transaction instead of escaping it.
                $this->bus->dispatch(new ProcessMedia($asset->id->toRfc4122()));

                return $asset;
            },
        );
    }

    private function inspectAndValidate(
        StorageObjectId $object,
        UploadSession $session,
    ): InspectedContent {
        $content = $this->inspector->inspect($object);
        $this->contentPolicy->assertAllowed($content);

        if ($content->byteSize !== $session->expectedSize) {
            throw new \DomainException('Received upload size does not match expected size.');
        }

        ($this->structureValidator)($object, $content->mediaType);

        return $content;
    }

    private function existingAsset(Uuid $sessionId): ?MediaAsset
    {
        $mediaId = $this->finalizations->findMediaId($sessionId);

        return $mediaId === null ? null : $this->media->get($mediaId);
    }

    private function assertOwner(UploadSession $session, Uuid $actingUserId): void
    {
        if (!$session->userId->equals($actingUserId)) {
            throw new \DomainException('Upload session does not belong to the acting user.');
        }
    }

    private function assertStoredObjectMatchesInspection(
        StoredObject $stored,
        InspectedContent $content,
    ): void {
        if ($stored->byteSize !== $content->byteSize) {
            throw new \RuntimeException(
                'Promoted original size differs from the validated upload.',
            );
        }

        if (
            $stored->checksum === null
            || !hash_equals($content->sha256, $stored->checksum)
        ) {
            throw new \RuntimeException(
                'Promoted original checksum differs from the validated upload.',
            );
        }
    }
}
