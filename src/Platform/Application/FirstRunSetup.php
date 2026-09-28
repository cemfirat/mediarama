<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\SetupCompletionMethod;
use Mediarama\Platform\Domain\SetupState;

interface FirstRunSetup
{
    public function state(): SetupState;

    public function complete(
        string $username,
        string $password,
        ?string $email,
        DeploymentProfile $profile,
        SetupCompletionMethod $completedVia,
        bool $allowInactiveRecovery = false,
    ): FirstRunSetupResult;
}
