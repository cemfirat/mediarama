<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Symfony\Component\Uid\Uuid;

interface UploadFinalizationCriticalSection
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(Uuid $sessionId, callable $operation): mixed;
}
