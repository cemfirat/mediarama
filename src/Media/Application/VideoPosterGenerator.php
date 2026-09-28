<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;

interface VideoPosterGenerator
{
    public function generate(
        MediaAsset $media,
        VideoPosterProfile $profile,
        int $processingVersion,
    ): MediaDerivative;
}
