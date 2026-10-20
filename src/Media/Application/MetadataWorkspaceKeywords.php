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

    /**
     * @param list<Uuid> $mediaIds
     * @param list<string> $values
     * @return list<array{
     *     id:string,
     *     filename:string,
     *     title:?string,
     *     current:list<string>,
     *     result:list<string>,
     *     added:list<string>,
     *     removed:list<string>,
     *     changed:bool
     * }>
     */
    public function previewMany(
        Uuid $ownerId,
        array $mediaIds,
        string $action,
        array $values,
    ): array;

    /**
     * Apply one explicit keyword operation atomically to requester-owned assets.
     *
     * @param list<Uuid> $mediaIds
     * @param list<string> $values
     * @return int number of MediaAssets changed
     */
    public function updateMany(
        Uuid $ownerId,
        array $mediaIds,
        string $action,
        array $values,
    ): int;
}
