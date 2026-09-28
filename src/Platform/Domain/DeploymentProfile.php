<?php

declare(strict_types=1);

namespace Mediarama\Platform\Domain;

enum DeploymentProfile: string
{
    case PrivateWorkspace = 'private_workspace';
    case PublicPublishing = 'public_publishing';
    case InternalIsolated = 'internal_isolated';
}
