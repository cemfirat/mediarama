<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

final readonly class OrganizationAiPresentationAsset
{
    private const MAX_BYTES = 10485760;
    private const MAX_DIMENSION = 4096;

    public function __construct(
        public Uuid $mediaId,
        public string $mimeType,
        public int $width,
        public int $height,
        public string $bytes,
    ) {
        if (!str_starts_with($mimeType, 'image/')) {
            throw new \InvalidArgumentException(
                'Organization AI presentation must be an image derivative.',
            );
        }

        if (
            $width < 1
            || $height < 1
            || $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
        ) {
            throw new \InvalidArgumentException(
                'Organization AI presentation dimensions are invalid.',
            );
        }

        $length = strlen($bytes);
        if ($length < 1 || $length > self::MAX_BYTES) {
            throw new \InvalidArgumentException(
                'Organization AI presentation derivative is empty or too large.',
            );
        }
    }
}
