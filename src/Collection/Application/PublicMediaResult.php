<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Symfony\Component\Uid\Uuid;

final readonly class PublicMediaResult
{
    public function __construct(
        public Uuid $id,
        public ?string $title,
        public ?string $description,
        public string $mimeType,
        public string $mediaType,
        public ?int $width,
        public ?int $height,
        public int $position,
        public ?int $thumbnailVersion,
        public ?int $previewVersion,
    ) {
    }
}
