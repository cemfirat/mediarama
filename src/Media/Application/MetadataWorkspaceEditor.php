<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

interface MetadataWorkspaceEditor
{
    /**
     * Apply explicit single-asset canonical metadata changes.
     *
     * Keys not present are left unchanged. Every supplied field carries one
     * explicit action: set, clear or revert. Revert resolves only through the
     * typed canonical-to-source mapping and fails closed when unavailable.
     *
     * @param array<string,array{action:string,value:?string}> $changes
     */
    public function update(
        Uuid $ownerId,
        Uuid $mediaId,
        array $changes,
    ): void;
}
