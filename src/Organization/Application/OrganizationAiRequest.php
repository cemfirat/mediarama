<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

final readonly class OrganizationAiRequest
{
    /**
     * @param list<OrganizationAiMediaInput> $media
     */
    public function __construct(
        public OrganizationAiRequestSummary $summary,
        public array $media,
        public OrganizationAiPresentationGateway $presentations,
    ) {
        if (count($media) !== $summary->mediaCount) {
            throw new \InvalidArgumentException(
                'Organization AI request media does not match the approved summary.',
            );
        }

        foreach ($media as $item) {
            if (!$item instanceof OrganizationAiMediaInput) {
                throw new \InvalidArgumentException(
                    'Organization AI request contains an invalid media input.',
                );
            }
        }
    }
}
