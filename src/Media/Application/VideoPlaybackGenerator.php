<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;

interface VideoPlaybackGenerator
{
    public function generate(
        MediaAsset $media,
        VideoPlaybackProfile $profile,
        int $processingVersion,
    ): MediaDerivative;
}
