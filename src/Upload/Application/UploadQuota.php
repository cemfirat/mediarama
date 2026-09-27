<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Symfony\Component\Uid\Uuid;

interface UploadQuota
{
    /**
     * Persist a session-scoped reservation and execute UploadSession
     * persistence in the same database transaction.
     *
     * @param callable(): void $persistSession
     */
    public function reserve(
        Uuid $sessionId,
        Uuid $userId,
        int $bytes,
        callable $persistSession,
    ): void;

    /**
     * Convert a reservation into committed usage.
     *
     * Committed usage is derived from MediaAsset rows, so this removes only
     * the temporary reservation rather than incrementing a second counter.
     */
    public function commit(Uuid $sessionId): void;

    /**
     * Release an uncommitted reservation after terminal failure.
     *
     * This is intentionally idempotent and distinct from successful commit.
     */
    public function release(Uuid $sessionId): void;
}
