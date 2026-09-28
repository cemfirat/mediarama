<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaType;
use Symfony\Component\Uid\Uuid;

final readonly class RegenerateMediaDerivatives
{
    public function __construct(
        private MediaAssetRepository $media,
        private GenerateImageDerivatives $images,
        private GenerateVideoDerivatives $videos,
    ) {
    }

    public function __invoke(Uuid $mediaId): int
    {
        $asset = $this->media->get($mediaId);

        return match ($asset->mediaType) {
            MediaType::Image => $this->images->regenerate($asset),
            MediaType::Video => $this->videos->regenerate($asset),
            default => throw new \DomainException(
                'Versioned derivative regeneration currently supports image and video media only.',
            ),
        };
    }
}
