<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;

final readonly class OrganizationAiRequestSummary
{
    /**
     * @param list<OrganizationAiCapability> $capabilities
     * @param array<string,int> $mediaTypeCounts
     */
    public function __construct(
        public array $capabilities,
        public OrganizationAiInputMode $inputMode,
        public int $mediaCount,
        public array $mediaTypeCounts,
        public int $presentationMediaCount,
        public bool $includeCreator,
        public bool $includeLocationName,
    ) {
        if ($capabilities === []) {
            throw new \InvalidArgumentException(
                'Organization AI request requires at least one capability.',
            );
        }

        $seen = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof OrganizationAiCapability) {
                throw new \InvalidArgumentException(
                    'Organization AI request capabilities are invalid.',
                );
            }

            if (isset($seen[$capability->value])) {
                throw new \InvalidArgumentException(
                    'Organization AI request capabilities must not contain duplicates.',
                );
            }

            $seen[$capability->value] = true;
        }

        if ($mediaCount < 1 || $mediaCount > 50000) {
            throw new \InvalidArgumentException(
                'Organization AI request media count is invalid.',
            );
        }

        $sum = 0;
        foreach ($mediaTypeCounts as $type => $count) {
            if (
                !is_string($type)
                || $type === ''
                || !is_int($count)
                || $count < 0
            ) {
                throw new \InvalidArgumentException(
                    'Organization AI media type counts are invalid.',
                );
            }

            $sum += $count;
        }

        if ($sum !== $mediaCount) {
            throw new \InvalidArgumentException(
                'Organization AI media type counts do not match the scope.',
            );
        }

        if (
            $presentationMediaCount < 0
            || $presentationMediaCount > $mediaCount
        ) {
            throw new \InvalidArgumentException(
                'Organization AI presentation media count is invalid.',
            );
        }
    }

    public function sendsMetadata(): bool
    {
        return true;
    }

    public function sendsOriginals(): bool
    {
        return false;
    }
}
