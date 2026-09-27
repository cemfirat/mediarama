<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Authorization;

use Mediarama\Collection\Application\CollectionUploadPermissionRepository;
use Mediarama\Upload\Application\UploadDestinationAuthorizer;
use Mediarama\Upload\Domain\UploadProblem;
use Symfony\Component\Uid\Uuid;

final readonly class CollectionUploadDestinationAuthorizer implements UploadDestinationAuthorizer
{
    public function __construct(private CollectionUploadPermissionRepository $permissions)
    {
    }

    public function assertCanUpload(Uuid $userId, ?Uuid $collectionId): void
    {
        if ($collectionId === null) {
            return;
        }

        if (!$this->permissions->userCanUpload($userId, $collectionId)) {
            throw UploadProblem::destinationForbidden();
        }
    }
}
