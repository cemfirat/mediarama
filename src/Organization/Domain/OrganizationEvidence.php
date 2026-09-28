<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

final readonly class OrganizationEvidence
{
    public string $summary;

    public function __construct(
        public OrganizationEvidenceSource $source,
        string $summary,
    ) {
        $summary = trim($summary);
        $length = iconv_strlen($summary, 'UTF-8');

        if ($summary === '' || $length === false || $length > 2000) {
            throw new \InvalidArgumentException(
                'Organization evidence summary must contain 1-2000 characters.',
            );
        }

        $this->summary = $summary;
    }

}
