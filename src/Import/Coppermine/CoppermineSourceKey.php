<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

final readonly class CoppermineSourceKey
{
    private const PREFIX = 'coppermine:';
    private const MAX_SOURCE_ID_LENGTH = 48;

    private string $sourceId;

    public function __construct(string $sourceId)
    {
        $sourceId = trim($sourceId);

        if ($sourceId === '') {
            throw new \InvalidArgumentException('COPPERMINE_SOURCE_ID must not be empty.');
        }

        if (strlen($sourceId) > self::MAX_SOURCE_ID_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'COPPERMINE_SOURCE_ID must be at most %d ASCII characters.',
                self::MAX_SOURCE_ID_LENGTH,
            ));
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $sourceId) !== 1) {
            throw new \InvalidArgumentException(
                'COPPERMINE_SOURCE_ID may contain only ASCII letters, digits, dot, underscore and hyphen, and must start with a letter or digit.',
            );
        }

        $this->sourceId = $sourceId;
    }

    public function id(): string
    {
        return $this->sourceId;
    }

    public function value(): string
    {
        return self::PREFIX.$this->sourceId;
    }
}
