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
        public ?UploadFailureCode $lastFailureCode = null,
        public ?UploadFailureStage $lastFailureStage = null,
        public ?bool $lastFailureRetryable = null,
        public ?DateTimeImmutable $lastFailedAt = null,
    ) {
        if ($expectedSize < 0) {
            throw new \InvalidArgumentException('Expected upload size must not be negative.');
        }
        if (trim($originalFilename) === '') {
            throw new \InvalidArgumentException('Original filename must not be empty.');
        }

        $failureValues = [
            $lastFailureCode,
            $lastFailureStage,
            $lastFailureRetryable,
            $lastFailedAt,
        ];
        $failureValueCount = count(array_filter(
            $failureValues,
            static fn (mixed $value): bool => $value !== null,
        ));

        if (!in_array($failureValueCount, [0, 4], true)) {
            throw new \InvalidArgumentException('Upload failure metadata must be entirely null or entirely populated.');
        }

        if (
            $lastFailureCode !== null
            && (
                $lastFailureCode->stage() !== $lastFailureStage
                || $lastFailureCode->isRetryable() !== $lastFailureRetryable
            )
        ) {
            throw new \InvalidArgumentException('Upload failure metadata is internally inconsistent.');
        }

        if ($lastFailureCode?->isTerminal() === true && $status !== UploadStatus::Failed) {
            throw new \InvalidArgumentException('A terminal upload failure must use failed status.');
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
        ?UploadFailureCode $lastFailureCode = null,
        ?UploadFailureStage $lastFailureStage = null,
        ?bool $lastFailureRetryable = null,
        ?DateTimeImmutable $lastFailedAt = null,
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
            $lastFailureCode,
            $lastFailureStage,
            $lastFailureRetryable,
            $lastFailedAt,
        );
    }

    public function begin(): void
    {
        if ($this->status !== UploadStatus::Created) {
            throw new \DomainException('Only a new upload session can begin uploading.');
        }
        $this->status = UploadStatus::Uploading;
        $this->clearFailureState();
        $this->touch();
    }

    public function markUploaded(): void
    {
        if (!in_array($this->status, [UploadStatus::Created, UploadStatus::Uploading], true)) {
            throw new \DomainException('Upload session cannot be marked uploaded from its current state.');
        }
        $this->status = UploadStatus::Uploaded;
        $this->clearFailureState();
        $this->touch();
    }

    public function beginFinalization(): void
    {
        if ($this->status !== UploadStatus::Uploaded) {
            throw new \DomainException('Only an uploaded session can be finalized.');
        }
        $this->status = UploadStatus::Finalizing;
        $this->clearFailureState();
        $this->touch();
    }

    public function complete(): void
    {
        if ($this->status !== UploadStatus::Finalizing) {
            throw new \DomainException('Only a finalizing upload can complete.');
        }
        $this->status = UploadStatus::Completed;
        $this->clearFailureState();
        $this->touch();
    }

    public function recordFailure(
        UploadFailureCode $code,
        ?DateTimeImmutable $failedAt = null,
    ): void {
        if ($this->status === UploadStatus::Completed) {
            throw new \DomainException('A completed upload cannot record an upload failure.');
        }

        $this->lastFailureCode = $code;
        $this->lastFailureStage = $code->stage();
        $this->lastFailureRetryable = $code->isRetryable();
        $this->lastFailedAt = $failedAt ?? new DateTimeImmutable();

        if ($code->isTerminal()) {
            $this->status = UploadStatus::Failed;
        }

        $this->touch();
    }

    public function clearFailure(): void
    {
        if ($this->lastFailureCode === null) {
            return;
        }

        $this->clearFailureState();
        $this->touch();
    }

    public function isExpired(DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        return $now >= $this->expiresAt && $this->status !== UploadStatus::Completed;
    }

    private function clearFailureState(): void
    {
        $this->lastFailureCode = null;
        $this->lastFailureStage = null;
        $this->lastFailureRetryable = null;
        $this->lastFailedAt = null;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
