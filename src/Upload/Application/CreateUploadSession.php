<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadSession;
use Symfony\Component\Uid\Uuid;

final readonly class CreateUploadSession
{
    public function __construct(
        private UploadSessionRepository $sessions,
        private UploadDestinationAuthorizer $authorizer,
        private UploadPolicy $policy,
        private UploadQuota $quota,
    ) {
    }

    public function __invoke(
        Uuid $userId,
        ?Uuid $targetCollectionId,
        string $originalFilename,
        int $expectedSize,
        ?string $expectedMime = null,
    ): UploadSession {
        if (trim($originalFilename) === '') {
            throw UploadProblem::request(
                'invalid_upload_request',
                'Original filename must not be empty.',
            );
        }

        $this->authorizer->assertCanUpload($userId, $targetCollectionId);
        $this->policy->assertAssetSize($expectedSize);

        $session = UploadSession::create(
            $userId,
            $targetCollectionId,
            $originalFilename,
            $expectedSize,
            $expectedMime,
        );

        $this->quota->reserve(
            $session->id,
            $userId,
            $expectedSize,
            function () use ($session): void {
                $this->sessions->save($session);
            },
        );

        return $session;
    }
}
