<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class PublicMediaDetailResult
{
    public function __construct(
        public Uuid $id,
        public ?string $title,
        public ?string $description,
        public string $mimeType,
        public string $mediaType,
        public ?int $width,
        public ?int $height,
        public ?int $durationMs,
        public ?int $thumbnailVersion,
        public ?int $previewVersion,
        public ?int $largeVersion,
        public ?int $videoPresentationVersion,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $publicUpdatedAt,
        public bool $indexable,
    ) {
    }

    public function preferredImageProfile(): ?string
    {
        if ($this->mediaType !== 'image') {
            return null;
        }

        if ($this->largeVersion !== null) {
            return 'large';
        }

        if ($this->previewVersion !== null) {
            return 'preview';
        }

        if ($this->thumbnailVersion !== null) {
            return 'thumbnail';
        }

        return null;
    }

    public function preferredImageVersion(): ?int
    {
        return match ($this->preferredImageProfile()) {
            'large' => $this->largeVersion,
            'preview' => $this->previewVersion,
            'thumbnail' => $this->thumbnailVersion,
            default => null,
        };
    }

    public function hasVideoPresentation(): bool
    {
        return $this->mediaType === 'video' && $this->videoPresentationVersion !== null;
    }
}
