<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Upload\Application\UploadFinalizationCriticalSection;
use Mediarama\Upload\Domain\UploadProblem;
use Symfony\Component\Uid\Uuid;

final readonly class DbalUploadFinalizationCriticalSection implements UploadFinalizationCriticalSection
{
    public function __construct(private Connection $connection)
    {
    }

    public function run(Uuid $sessionId, callable $operation): mixed
    {
        return $this->connection->transactional(function () use ($sessionId, $operation): mixed {
            $lockedId = $this->connection->fetchOne(
                'SELECT id FROM upload_sessions WHERE id = :id FOR UPDATE',
                ['id' => $sessionId->toRfc4122()],
            );

            if ($lockedId === false) {
                throw UploadProblem::sessionNotFound();
            }

            return $operation();
        });
    }
}
