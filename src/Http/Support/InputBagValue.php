<?php

declare(strict_types=1);

namespace Mediarama\Http\Support;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * Keeps request parsing semantics explicit across HttpFoundation upgrades.
 */
final class InputBagValue
{
    public static function integer(InputBag $input, string $key, int $default = 0): int
    {
        try {
            return $input->getInt($key, $default);
        } catch (\UnexpectedValueException $exception) {
            throw new \InvalidArgumentException(
                sprintf('Invalid integer parameter "%s".', $key),
                previous: $exception,
            );
        }
    }

    public static function boolean(InputBag $input, string $key, bool $default = false): bool
    {
        try {
            return $input->getBoolean($key, $default);
        } catch (\UnexpectedValueException $exception) {
            throw new \InvalidArgumentException(
                sprintf('Invalid boolean parameter "%s".', $key),
                previous: $exception,
            );
        }
    }

    public static function integerOrDefault(InputBag $input, string $key, int $default = 0): int
    {
        try {
            return $input->getInt($key, $default);
        } catch (\UnexpectedValueException) {
            return $default;
        }
    }

    public static function booleanOrDefault(InputBag $input, string $key, bool $default = false): bool
    {
        try {
            return $input->getBoolean($key, $default);
        } catch (\UnexpectedValueException) {
            return $default;
        }
    }
}
