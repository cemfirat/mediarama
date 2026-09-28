<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;
use Mediarama\Media\Domain\MediaType;

final readonly class LibraryMediaSearchCriteria
{
    public function __construct(
        public ?string $text = null,
        public ?string $creator = null,
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
        public ?string $lens = null,
        public ?string $mediaType = null,
        public ?string $locationName = null,
        public ?string $tag = null,
        public ?float $minimumRating = null,
        public ?string $orientation = null,
        public ?int $minimumIso = null,
        public ?int $maximumIso = null,
        public ?DateTimeImmutable $capturedFrom = null,
        public ?DateTimeImmutable $capturedUntil = null,
        public ?bool $hasLocation = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
        if ($limit < 1 || $limit > 200 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid media search pagination.');
        }

        if ($mediaType !== null && MediaType::tryFrom($mediaType) === null) {
            throw new \InvalidArgumentException('Invalid media type filter.');
        }

        if ($minimumRating !== null && ($minimumRating < 1.0 || $minimumRating > 5.0)) {
            throw new \InvalidArgumentException('Minimum average rating must be between 1 and 5.');
        }

        if (
            $orientation !== null
            && !in_array($orientation, ['portrait', 'landscape', 'square'], true)
        ) {
            throw new \InvalidArgumentException('Invalid orientation filter.');
        }

        if ($minimumIso !== null && $minimumIso <= 0) {
            throw new \InvalidArgumentException('Minimum ISO must be positive.');
        }

        if ($maximumIso !== null && $maximumIso <= 0) {
            throw new \InvalidArgumentException('Maximum ISO must be positive.');
        }

        if ($minimumIso !== null && $maximumIso !== null && $minimumIso > $maximumIso) {
            throw new \InvalidArgumentException('Minimum ISO cannot exceed maximum ISO.');
        }

        if ($capturedFrom !== null && $capturedUntil !== null && $capturedFrom > $capturedUntil) {
            throw new \InvalidArgumentException('Capture range is invalid.');
        }
    }
}
