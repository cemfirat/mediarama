<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Mediarama\Collection\Domain\SmartCollectionRule;
use Symfony\Component\Uid\Uuid;

interface SmartCollectionConfigurator
{
    public function configureSmart(
        Uuid $actorId,
        Uuid $collectionId,
        SmartCollectionRule $rule,
    ): void;

    public function configureManual(Uuid $actorId, Uuid $collectionId): void;
}
