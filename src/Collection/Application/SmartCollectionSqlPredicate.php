<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

final readonly class SmartCollectionSqlPredicate
{
    /**
     * @param array<string,mixed> $parameters
     */
    public function __construct(
        public string $sql,
        public array $parameters,
    ) {
    }
}
