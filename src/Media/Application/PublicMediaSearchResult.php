<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

final readonly class PublicMediaSearchResult
{
    public function __construct(
        public Uuid $id,
        public string $mimeType,
        public string $mediaType,
        public ?string $title,
        public ?string $description,
        public ?int $thumbnailVersion,
        public ?int $previewVersion,
    ) {
    }
}
