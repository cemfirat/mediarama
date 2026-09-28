<?php

declare(strict_types=1);

namespace Mediarama\Platform\Infrastructure\Security;

final readonly class BrowserSetupToken
{
    public function __construct(private string $expectedToken)
    {
    }

    public function isConfigured(): bool
    {
        return strlen($this->expectedToken) >= 32;
    }

    public function isValid(string $providedToken): bool
    {
        return $this->isConfigured()
            && $providedToken !== ''
            && hash_equals($this->expectedToken, $providedToken);
    }
}
