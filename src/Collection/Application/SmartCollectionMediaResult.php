<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class SmartCollectionMediaResult
{
    public function __construct(
        public Uuid $id,
        public string $mediaType,
        public ?string $title,
        public ?DateTimeImmutable $capturedAt,
        public ?string $creator,
        public ?string $cameraMake,
        public ?string $cameraModel,
        public ?string $lens,
        public ?string $locationName,
    ) {
    }
}
