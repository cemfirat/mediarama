<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

interface MediaDerivativeRegenerationLock
{
    /**
     * Executes one regeneration operation exclusively for a media/kind pair.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function synchronized(Uuid $mediaId, string $kind, callable $operation): mixed;
}
