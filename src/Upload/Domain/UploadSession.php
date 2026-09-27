<?php

declare(strict_types=1);

namespace Mediarama\Upload\Domain;

use DateInterval;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final class UploadSession
{
    private function __construct(
        public readonly Uuid $id,
        public readonly Uuid $userId,
        public readonly ?Uuid $targetCollectionId,
        public readonly string $originalFilename,
        public readonly int $expectedSize,
        public readonly ?string $expectedMime,
        public readonly string $temporaryStorageKey,
        public UploadStatus $status,
        public readonly DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?UploadFailure $lastFailure = null,
    ) {
        if ($expectedSize < 0) {
            throw new \InvalidArgumentException('Expected upload size must not be negative.');
        }
        if (trim($originalFilename) === '') {
            throw new \InvalidArgumentException('Original filename must not be empty.');
        }
    }

    public static function create(
        Uuid $userId,
        ?Uuid $targetCollectionId,
        string $originalFilename,
        int $expectedSize,
        ?string $expectedMime = null,
    ): self {
        $id = Uuid::v7();
        $now = new DateTimeImmutable();

        return new self(
            $id,
            $userId,
            $targetCollectionId,
            trim($originalFilename),
            $expectedSize,
            $expectedMime,
            sprintf('temporary/%s/source', $id->toRfc4122()),
            UploadStatus::Created,
            $now->add(new DateInterval('PT24H')),
            $now,
            $now,
            null,
        );
    }


    public static function reconstitute(
        Uuid $id,
        Uuid $userId,
        ?Uuid $targetCollectionId,
        string $originalFilename,
        int $expectedSize,
        ?string $expectedMime,
        string $temporaryStorageKey,
        UploadStatus $status,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?UploadFailure $lastFailure = null,
    ): self {
        return new self(
            $id,
            $userId,
            $targetCollectionId,
            $originalFilename,
            $expectedSize,
            $expectedMime,
            $temporaryStorageKey,
            $status,
            $expiresAt,
            $createdAt,
            $updatedAt,
            $lastFailure,
        );
    }

    public function begin(): void
    {
        if ($this->status !== UploadStatus::Created) {
            throw new \DomainException('Only a new upload session can begin uploading.');
        }
        $this->status = UploadStatus::Uploading;
        $this->touch();
    }

    public function markUploaded(): void
    {
        if (!in_array($this->status, [UploadStatus::Created, UploadStatus::Uploading], true)) {
            throw new \DomainException('Upload session cannot be marked uploaded from its current state.');
        }
        $this->status = UploadStatus::Uploaded;
        $this->clearFailure();
        $this->touch();
    }

    public function beginFinalization(): void
    {
        if ($this->status !== UploadStatus::Uploaded) {
            throw new \DomainException('Only an uploaded session can be finalized.');
        }
        $this->status = UploadStatus::Finalizing;
        $this->touch();
    }

    public function complete(): void
    {
        if ($this->status !== UploadStatus::Finalizing) {
            throw new \DomainException('Only a finalizing upload can complete.');
        }
        $this->status = UploadStatus::Completed;
        $this->clearFailure();
        $this->touch();
    }

    public function recordFailure(
        string $code,
        UploadFailureStage $stage,
        bool $retryable,
    ): void {
        $this->lastFailure = new UploadFailure(
            $code,
            $stage,
            $retryable,
            new DateTimeImmutable(),
        );
        $this->touch();
    }

    public function failTerminal(string $code, UploadFailureStage $stage): void
    {
        if ($this->status === UploadStatus::Completed) {
            throw new \DomainException('A completed upload cannot fail.');
        }

        $this->status = UploadStatus::Failed;
        $this->recordFailure($code, $stage, false);
    }

    public function clearFailure(): void
    {
        if ($this->lastFailure === null) {
            return;
        }

        $this->lastFailure = null;
        $this->touch();
    }

    public function isExpired(DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        return $now >= $this->expiresAt && $this->status !== UploadStatus::Completed;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
