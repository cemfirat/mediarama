<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class LibraryMediaSearchResult
{
    public function __construct(
        public Uuid $id,
        public string $originalFilename,
        public string $mimeType,
        public ?string $title,
        public ?string $description,
        public ?DateTimeImmutable $capturedAt,
        public ?string $creator,
        public ?string $cameraMake,
        public ?string $cameraModel,
        public ?string $lens,
        public ?int $iso,
        public ?string $locationName,
        public bool $metadataWorkspaceAvailable,
    ) {
    }
}
