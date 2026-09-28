<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationMetadataRecord
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
}
