<?php

declare(strict_types=1);

namespace Mediarama\Platform\Domain;

enum SetupStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
}
