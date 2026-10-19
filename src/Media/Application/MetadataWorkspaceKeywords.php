<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Symfony\Component\Uid\Uuid;

interface MetadataWorkspaceKeywords
{
    /**
     * @return list<array{name:string,source:string}>
     */
    public function read(Uuid $ownerId, Uuid $mediaId): array;

    /**
     * Apply one explicit relational keyword operation.
     *
     * Supported actions are replace, append, remove, clear and revert.
     * Revert resolves only from the persisted immutable source snapshot.
     *
     * @param list<string> $values
     */
    public function update(
        Uuid $ownerId,
        Uuid $mediaId,
        string $action,
        array $values,
    ): void;
}
