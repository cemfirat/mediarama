<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Authorization;

use Mediarama\Collection\Application\CollectionAccessPolicy;
use Mediarama\Upload\Application\UploadDestinationAuthorizer;
use Mediarama\Upload\Application\UploadProblem;
use Symfony\Component\Uid\Uuid;

final readonly class CollectionUploadDestinationAuthorizer implements UploadDestinationAuthorizer
{
    public function __construct(private CollectionAccessPolicy $access)
    {
    }

    public function assertCanUpload(Uuid $userId, ?Uuid $collectionId): void
    {
        if ($collectionId === null) {
            return;
        }

        if (!$this->access->canAddMedia($userId, $collectionId)) {
            throw UploadProblem::request(
                'upload_destination_forbidden',
                'User is not allowed to upload to the target collection.',
            );
        }
    }
}
