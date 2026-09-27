<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

final readonly class DerivativeCleanupSummary
{
    public function __construct(
        public int $generations,
        public int $derivatives,
        public int $bytes,
    ) {
        if ($generations < 0 || $derivatives < 0 || $bytes < 0) {
            throw new \InvalidArgumentException('Derivative cleanup summary values must be non-negative.');
        }
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }
}
