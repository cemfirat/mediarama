<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

final class CoppermineFavoriteDecoder
{
    /** @return list<string> */
    public function decode(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || $decoded === '') {
            return [];
        }

        try {
            $value = @unserialize($decoded, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $candidate) {
            if (is_int($candidate)) {
                $id = $candidate;
            } elseif (is_string($candidate) && ctype_digit($candidate)) {
                $id = (int) $candidate;
            } else {
                continue;
            }

            if ($id > 0) {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
