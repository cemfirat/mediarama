<?php

declare(strict_types=1);

namespace Mediarama\Security\Application;

use Symfony\Component\Uid\Uuid;

interface SystemAdministrationPolicy
{
    public function canAdminister(Uuid $userId): bool;
}
