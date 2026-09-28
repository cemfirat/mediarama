<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Mediarama\Platform\Domain\DeploymentProfile;
use Symfony\Component\Uid\Uuid;

final readonly class FirstRunSetupResult
{
    public function __construct(
        public Uuid $administratorId,
        public string $username,
        public DeploymentProfile $profile,
        public bool $shouldAuthenticate,
        public bool $claimedExistingIdentity,
    ) {
    }
}
