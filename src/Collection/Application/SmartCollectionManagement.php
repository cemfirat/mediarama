<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Mediarama\Collection\Domain\SmartCollectionRule;
use Symfony\Component\Uid\Uuid;

interface SmartCollectionManagement
{
    /** @return list<SmartCollectionManagementResult> */
    public function owned(Uuid $ownerId): array;

    public function getOwned(
        Uuid $ownerId,
        Uuid $collectionId,
    ): ?SmartCollectionManagementResult;

    public function create(
        Uuid $ownerId,
        string $title,
        ?string $description,
        SmartCollectionRule $rule,
    ): Uuid;

    public function update(
        Uuid $ownerId,
        Uuid $collectionId,
        string $title,
        ?string $description,
        SmartCollectionRule $rule,
    ): void;

    public function delete(
        Uuid $ownerId,
        Uuid $collectionId,
    ): void;
}
