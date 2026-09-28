<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Symfony\Component\Uid\Uuid;

final readonly class PublicSmartCollectionCoverResult
{
    public function __construct(
        public Uuid $mediaId,
        public int $thumbnailVersion,
    ) {
    }
}
