<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

interface MetadataWorkspaceEditor
{
    /**
     * Apply explicit single-asset canonical metadata changes.
     *
     * Keys not present are left unchanged. A null value is an explicit clear.
     *
     * @param array<string,?string> $changes
     */
    public function update(
        Uuid $ownerId,
        Uuid $mediaId,
        array $changes,
    ): void;
}
