<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

final readonly class CoppermineMigrationPreflightReport
{
    /** @param list<string> $blockers */
    public function __construct(public array $blockers)
    {
    }

    public function isClean(): bool
    {
        return $this->blockers === [];
    }
}
