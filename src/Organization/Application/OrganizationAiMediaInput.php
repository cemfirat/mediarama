<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationAiMediaInput
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public Uuid $id,
        public string $mediaType,
        public ?DateTimeImmutable $capturedAt,
        public ?string $creator,
        public ?string $cameraMake,
        public ?string $cameraModel,
        public ?string $lens,
        public ?string $locationName,
        public ?int $width,
        public ?int $height,
        public ?float $ratingAverage,
        public array $tags,
        public bool $hasCollectionMembership,
    ) {
    }

    public static function fromMetadata(
        OrganizationMetadataRecord $record,
        bool $includeCreator,
        bool $includeLocationName,
    ): self {
        return new self(
            $record->id,
            $record->mediaType,
            $record->capturedAt,
            $includeCreator ? $record->creator : null,
            $record->cameraMake,
            $record->cameraModel,
            $record->lens,
            $includeLocationName ? $record->locationName : null,
            $record->width,
            $record->height,
            $record->ratingAverage,
            $record->tags,
            $record->hasCollectionMembership,
        );
    }
}
