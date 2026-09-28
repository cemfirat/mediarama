<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

final readonly class ApprovedOrganizationAiPresentationGateway implements OrganizationAiPresentationGateway
{
    /** @var array<string,true> */
    private array $approved;

    /**
     * @param list<Uuid> $approvedMediaIds
     */
    public function __construct(
        private Uuid $requesterId,
        private OrganizationAiPresentationAssetReader $reader,
        array $approvedMediaIds,
    ) {
        $approved = [];
        foreach ($approvedMediaIds as $mediaId) {
            if (!$mediaId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Approved AI presentation scope must contain UUID objects.',
                );
            }

            $approved[$mediaId->toRfc4122()] = true;
        }

        $this->approved = $approved;
    }

    public function presentation(
        Uuid $mediaId,
    ): ?OrganizationAiPresentationAsset {
        if (!isset($this->approved[$mediaId->toRfc4122()])) {
            return null;
        }

        return $this->reader->read(
            $this->requesterId,
            $mediaId,
        );
    }
}
