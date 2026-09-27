<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;

final readonly class MediaSearchCriteria
{
    public function __construct(
        public ?string $text = null,
        public ?string $creator = null,
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
        public ?string $lens = null,
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
