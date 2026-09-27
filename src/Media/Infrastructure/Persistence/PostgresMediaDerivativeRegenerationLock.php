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
        $locked = (int) $this->connection->fetchOne(
            'SELECT CASE WHEN pg_try_advisory_lock(hashtextextended(:key, 0)) THEN 1 ELSE 0 END',
            ['key' => $key],
        );

        if ($locked !== 1) {
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
                $unlocked = (int) $this->connection->fetchOne(
                    'SELECT CASE WHEN pg_advisory_unlock(hashtextextended(:key, 0)) THEN 1 ELSE 0 END',
                    ['key' => $key],
                );

                if ($unlocked !== 1 && $operationError === null) {
                    throw new \RuntimeException('Unable to release derivative regeneration advisory lock.');
                }
            } catch (\Throwable $unlockError) {
                if ($operationError === null) {
                    throw $unlockError;
                }
            }
        }
    }
}
