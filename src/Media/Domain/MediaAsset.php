<?php

declare(strict_types=1);

namespace Mediarama\Media\Domain;

use DateTimeImmutable;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

final class MediaAsset
{
    /** @param array<string, mixed> $metadata */
    private function __construct(
        public readonly Uuid $id,
        public readonly ?Uuid $ownerId,
        public readonly StorageObjectId $original,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly MediaType $mediaType,
        public readonly int $byteSize,
        public readonly string $checksumSha256,
        public ProcessingState $processingState,
        public ModerationState $moderationState,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?DateTimeImmutable $capturedAt = null,
        public array $metadata = [],
        public array $metadataProvenance = [],
        public ?string $creator = null,
        public ?string $copyright = null,
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
        public ?string $lens = null,
        public ?int $iso = null,
        public ?string $aperture = null,
        public ?string $exposureTime = null,
        public ?string $focalLength = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $locationName = null,
        public SearchIndexPolicy $searchIndexPolicy = SearchIndexPolicy::Inherit,
    ) {
        if ($byteSize < 0) {
            throw new \InvalidArgumentException('Media byte size must not be negative.');
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $checksumSha256)) {
            throw new \InvalidArgumentException('Media checksum must be a lowercase SHA-256 hex string.');
        }
    }


    /**
     * @param array<string, mixed> $metadata
     * @param array<string, string> $metadataProvenance
     */
    public static function reconstitute(
        Uuid $id,
        ?Uuid $ownerId,
        StorageObjectId $original,
        string $originalFilename,
        string $mimeType,
        MediaType $mediaType,
        int $byteSize,
        string $checksumSha256,
        ProcessingState $processingState,
        ModerationState $moderationState,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?int $width = null,
        ?int $height = null,
        ?int $durationMs = null,
        ?string $title = null,
        ?string $description = null,
        ?DateTimeImmutable $capturedAt = null,
        array $metadata = [],
        array $metadataProvenance = [],
        ?string $creator = null,
        ?string $copyright = null,
        ?string $cameraMake = null,
        ?string $cameraModel = null,
        ?string $lens = null,
        ?int $iso = null,
        ?string $aperture = null,
        ?string $exposureTime = null,
        ?string $focalLength = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $locationName = null,
        SearchIndexPolicy $searchIndexPolicy = SearchIndexPolicy::Inherit,
    ): self {
        return new self(
            $id,
            $ownerId,
            $original,
            $originalFilename,
            $mimeType,
            $mediaType,
            $byteSize,
            $checksumSha256,
            $processingState,
            $moderationState,
            $createdAt,
            $updatedAt,
            $width,
            $height,
            $durationMs,
            $title,
            $description,
            $capturedAt,
            $metadata,
            $metadataProvenance,
            $creator,
            $copyright,
            $cameraMake,
            $cameraModel,
            $lens,
            $iso,
            $aperture,
            $exposureTime,
            $focalLength,
            $latitude,
            $longitude,
            $locationName,
            $searchIndexPolicy,
        );
    }

    /** @param array<string, mixed> $metadata */
    public static function createWithId(
        Uuid $id,
        ?Uuid $ownerId,
        StorageObjectId $original,
        string $originalFilename,
        string $mimeType,
        MediaType $mediaType,
        int $byteSize,
        string $checksumSha256,
        array $metadata = [],
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            $id,
            $ownerId,
            $original,
            $originalFilename,
            $mimeType,
            $mediaType,
            $byteSize,
            $checksumSha256,
            ProcessingState::Processing,
            ModerationState::Draft,
            $now,
            $now,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public static function create(
        ?Uuid $ownerId,
        StorageObjectId $original,
        string $originalFilename,
        string $mimeType,
        MediaType $mediaType,
        int $byteSize,
        string $checksumSha256,
        array $metadata = [],
    ): self {
        return self::createWithId(
            Uuid::v7(),
            $ownerId,
            $original,
            $originalFilename,
            $mimeType,
            $mediaType,
            $byteSize,
            $checksumSha256,
            $metadata,
        );
    }


    /**
     * @param array<string, mixed> $embedded
     * @param array<string, mixed> $canonical
     */
    public function applyEmbeddedMetadata(
        array $embedded,
        array $canonical,
        MetadataProvenance $provenance,
    ): void {
        $this->metadata = $embedded;

        foreach ($canonical as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $property = match ($field) {
                'captured_at' => 'capturedAt',
                'camera_make' => 'cameraMake',
                'camera_model' => 'cameraModel',
                'exposure_time' => 'exposureTime',
                'focal_length' => 'focalLength',
                'location_name' => 'locationName',
                default => $field,
            };

            if (!property_exists($this, $property)) {
                continue;
            }

            if ($this->{$property} === null || $this->{$property} === '') {
                $this->{$property} = $value;
                $this->metadataProvenance[$field] = $provenance->value;
            }
        }

        $this->updatedAt = new DateTimeImmutable();
    }

    public function editMetadata(string $field, mixed $value): void
    {
        $property = match ($field) {
            'captured_at' => 'capturedAt',
            'camera_make' => 'cameraMake',
            'camera_model' => 'cameraModel',
            'exposure_time' => 'exposureTime',
            'focal_length' => 'focalLength',
            'location_name' => 'locationName',
            default => $field,
        };

        $editable = [
            'title', 'description', 'capturedAt', 'creator', 'copyright',
            'cameraMake', 'cameraModel', 'lens', 'iso', 'aperture',
            'exposureTime', 'focalLength', 'latitude', 'longitude', 'locationName',
        ];

        if (!in_array($property, $editable, true)) {
            throw new \InvalidArgumentException(sprintf('Metadata field "%s" is not editable.', $field));
        }

        $this->{$property} = $value;
        $this->metadataProvenance[$field] = MetadataProvenance::User->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function setImageGeometry(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Image dimensions must be positive.');
        }

        $this->width = $width;
        $this->height = $height;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function setVideoProperties(
        int $width,
        int $height,
        ?int $durationMs,
    ): void {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Video dimensions must be positive.');
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw new \InvalidArgumentException('Video duration must not be negative.');
        }

        $this->width = $width;
        $this->height = $height;
        $this->durationMs = $durationMs;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markReady(): void
    {
        $this->processingState = ProcessingState::Ready;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markFailed(): void
    {
        $this->processingState = ProcessingState::Failed;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function submitForReview(): void
    {
        if ($this->processingState !== ProcessingState::Ready) {
            throw new \DomainException('Only ready media can be submitted for review.');
        }

        $this->moderationState = ModerationState::PendingReview;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function publish(): void
    {
        if ($this->processingState !== ProcessingState::Ready) {
            throw new \DomainException('Only ready media can be published.');
        }

        $this->moderationState = ModerationState::Published;
        $this->updatedAt = new DateTimeImmutable();
    }
}
