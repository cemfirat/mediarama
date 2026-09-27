<?php

declare(strict_types=1);

namespace Mediarama\Export\Application;

use Mediarama\Export\Domain\MetadataExportProfile;

final readonly class MetadataExportPolicy
{
    public const CUSTOM_FIELDS = [
        'title',
        'description',
        'creator',
        'copyright',
        'location_name',
        'latitude',
        'longitude',
    ];

    /** @param list<string> $includedFields */
    public function __construct(
        public MetadataExportProfile $profile,
        public array $includedFields = [],
    ) {
        if ($profile !== MetadataExportProfile::Custom && $includedFields !== []) {
            throw new \InvalidArgumentException('Explicit fields are only valid for the custom export profile.');
        }

        if ($profile === MetadataExportProfile::Custom) {
            if (!array_is_list($includedFields)) {
                throw new \InvalidArgumentException('Custom export fields must be a list.');
            }

            foreach ($includedFields as $field) {
                if (!is_string($field) || $field === '') {
                    throw new \InvalidArgumentException('Custom export field names must be non-empty strings.');
                }
            }

            if (count(array_unique($includedFields)) !== count($includedFields)) {
                throw new \InvalidArgumentException('Custom export fields must not contain duplicates.');
            }

            $unsupported = array_values(array_diff($includedFields, self::CUSTOM_FIELDS));
            if ($unsupported !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported custom metadata field(s): %s.',
                    implode(', ', $unsupported),
                ));
            }
        }
    }

    public static function privacySafe(): self
    {
        return new self(MetadataExportProfile::PrivacySafe);
    }
}
