<?php

declare(strict_types=1);

namespace Mediarama\Platform\Domain;

enum SetupCompletionMethod: string
{
    case Migration = 'migration';
    case Browser = 'browser';
    case Cli = 'cli';
    case ExistingAdministrator = 'existing_admin';
}
