<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\ProcessMedia;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Application\ValidateStoredMediaStructure;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaToolRejected;
use Mediarama\Media\Domain\MediaToolUnavailable;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Upload\Domain\UploadFailureCode;
use Mediarama\Upload\Domain\UploadProblem;
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
        private UploadQuota $quota,
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
                $this->runCritical(
                    $sessionId,
                    $actingUserId,
                    function () use ($sessionId, $actingUserId): void {
                        $locked = $this->sessions->get($sessionId);
                        $this->assertOwner($locked, $actingUserId);

                        if (
                            $locked->status === UploadStatus::Finalizing
                            && $this->existingAsset($sessionId) !== null
                        ) {
                            // Repair the pre-hardening crash window where the
                            // mapping existed but the session was not completed.
                            // commit() is idempotent and normally a no-op for
                            // sessions created before persistent reservations.
                            $this->quota->commit($sessionId);
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
                throw UploadProblem::expired();
            }

            // Keep all expensive work outside the database row lock.
            try {
                $this->authorizer->assertCanUpload($actingUserId, $session->targetCollectionId);
                $content = $this->inspectAndValidate($temporary, $session);
            } catch (UploadProblem $problem) {
                $this->recordFailure($sessionId, $actingUserId, $problem);
                throw $problem;
            }

            $existing = $this->runCritical(
                $sessionId,
                $actingUserId,
                function () use ($sessionId, $actingUserId): ?MediaAsset {
                    $locked = $this->sessions->get($sessionId);
                    $this->assertOwner($locked, $actingUserId);

                    $existing = $this->existingAsset($sessionId);
                    if ($existing !== null) {
                        return $existing;
                    }

                    if ($locked->status === UploadStatus::Uploaded) {
                        if ($locked->isExpired()) {
                            throw UploadProblem::expired();
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

                    throw UploadProblem::invalidState();
                },
            );

            if ($existing !== null) {
                return $existing;
            }
        } elseif ($session->status !== UploadStatus::Finalizing) {
            throw UploadProblem::invalidState();
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
            $problem = UploadProblem::fromFailure(UploadFailureCode::FinalizationSourceMissing);
            $this->recordFailure($sessionId, $actingUserId, $problem);
            throw $problem;
        }

        if ($content === null) {
            try {
                $content = $this->inspectAndValidate($validationObject, $session);
            } catch (UploadProblem $problem) {
                $this->recordFailure($sessionId, $actingUserId, $problem);
                throw $problem;
            }
        }

        // LocalMediaStorage promotion is idempotent for the deterministic target.
        // A concurrent winner or a previous crashed request may already have moved it.
        try {
            $stored = $this->storage->promote($temporary, $permanent);
            $this->assertStoredObjectMatchesInspection($stored, $content);
        } catch (UploadProblem $problem) {
            $this->recordFailure($sessionId, $actingUserId, $problem);
            throw $problem;
        } catch (\Throwable $error) {
            $problem = UploadProblem::fromFailure(
                UploadFailureCode::FinalizationStorageUnavailable,
                $error,
            );
            $this->recordFailure($sessionId, $actingUserId, $problem);
            throw $problem;
        }

        return $this->runCritical(
            $sessionId,
            $actingUserId,
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
                    throw UploadProblem::invalidState();
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
                // The MediaAsset is canonical committed usage. Removing the
                // reservation in this same transaction converts the bytes
                // without maintaining a drift-prone committed counter.
                $this->quota->commit($sessionId);
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
        try {
            $content = $this->inspector->inspect($object);
        } catch (UploadProblem $problem) {
            throw $problem;
        } catch (\Throwable $error) {
            throw UploadProblem::fromFailure(UploadFailureCode::InspectionUnavailable, $error);
        }

        $this->contentPolicy->assertAllowed($content);

        if ($content->byteSize !== $session->expectedSize) {
            throw UploadProblem::fromFailure(UploadFailureCode::MediaSizeMismatch);
        }

        try {
            ($this->structureValidator)($object, $content->mediaType);
        } catch (MediaToolRejected $error) {
            throw UploadProblem::fromFailure(UploadFailureCode::MediaInvalid, $error);
        } catch (MediaToolUnavailable $error) {
            throw UploadProblem::fromFailure(UploadFailureCode::InspectionUnavailable, $error);
        } catch (UploadProblem $problem) {
            throw $problem;
        } catch (\Throwable $error) {
            throw UploadProblem::fromFailure(UploadFailureCode::InspectionUnavailable, $error);
        }

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
            throw UploadProblem::sessionNotFound();
        }
    }

    /**
     * Run one short finalization DB critical section. Unexpected infrastructure
     * failures are made observable without changing a recoverable finalizing
     * session into terminal failed state.
     */
    private function runCritical(
        Uuid $sessionId,
        Uuid $actingUserId,
        callable $operation,
    ): mixed {
        try {
            return $this->criticalSection->run($sessionId, $operation);
        } catch (UploadProblem $problem) {
            throw $problem;
        } catch (\Throwable $error) {
            $problem = UploadProblem::fromFailure(
                UploadFailureCode::FinalizationInterrupted,
                $error,
            );
            $this->recordFailure($sessionId, $actingUserId, $problem);
            throw $problem;
        }
    }

    private function recordFailure(
        Uuid $sessionId,
        Uuid $actingUserId,
        UploadProblem $problem,
    ): void {
        if ($problem->failureCode === null) {
            return;
        }

        try {
            $this->criticalSection->run(
                $sessionId,
                function () use ($sessionId, $actingUserId, $problem): void {
                    $locked = $this->sessions->get($sessionId);
                    $this->assertOwner($locked, $actingUserId);

                    $existing = $this->existingAsset($sessionId);
                    if (
                        $existing !== null
                        && !(
                            $locked->status === UploadStatus::Finalizing
                            && $problem->retryable
                        )
                    ) {
                        return;
                    }

                    if (!in_array(
                        $locked->status,
                        [UploadStatus::Uploaded, UploadStatus::Finalizing],
                        true,
                    )) {
                        return;
                    }

                    $locked->recordFailure($problem->failureCode);
                    if ($problem->failureCode->isTerminal()) {
                        $this->quota->release($sessionId);
                    }
                    $this->sessions->save($locked);
                },
            );
        } catch (UploadProblem $recordingProblem) {
            throw $recordingProblem;
        } catch (\Throwable $error) {
            throw UploadProblem::fromFailure(UploadFailureCode::FinalizationInterrupted, $error);
        }
    }

    private function assertStoredObjectMatchesInspection(
        StoredObject $stored,
        InspectedContent $content,
    ): void {
        if ($stored->byteSize !== $content->byteSize) {
            throw UploadProblem::fromFailure(UploadFailureCode::IntegrityMismatch);
        }

        if (
            $stored->checksum === null
            || !hash_equals($content->sha256, $stored->checksum)
        ) {
            throw UploadProblem::fromFailure(UploadFailureCode::IntegrityMismatch);
        }
    }
}
