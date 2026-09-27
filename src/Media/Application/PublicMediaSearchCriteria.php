<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

final readonly class PublicMediaSearchCriteria
{
    public function __construct(
        public ?string $text = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
        if ($limit < 1 || $limit > 200 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid public media search pagination.');
        }
    }
}
