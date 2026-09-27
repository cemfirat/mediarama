<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaDerivativeRegenerationLock;
use Symfony\Component\Uid\Uuid;

final readonly class PostgresMediaDerivativeRegenerationLock implements MediaDerivativeRegenerationLock
{
    public function __construct(private Connection $connection)
    {
    }

    public function synchronized(Uuid $mediaId, string $kind, callable $operation): mixed
    {
        $key = $mediaId->toRfc4122().':'.$kind;
        $locked = (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(hashtextextended(:key, 0))',
            ['key' => $key],
        );

        if (!$locked) {
            throw new \DomainException(sprintf(
                'Derivative regeneration is already running for media "%s" and kind "%s".',
                $mediaId->toRfc4122(),
                $kind,
            ));
        }

        $operationError = null;

        try {
            return $operation();
        } catch (\Throwable $error) {
            $operationError = $error;

            throw $error;
        } finally {
            try {
                $this->connection->fetchOne(
                    'SELECT pg_advisory_unlock(hashtextextended(:key, 0))',
                    ['key' => $key],
                );
            } catch (\Throwable $unlockError) {
                if ($operationError === null) {
                    throw $unlockError;
                }
            }
        }
    }
}
