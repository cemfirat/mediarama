<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Upload\Application\ExpiredUploadSessionRepository;
use Mediarama\Upload\Domain\UploadSession;

final readonly class DbalExpiredUploadSessionRepository implements ExpiredUploadSessionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findExpired(DateTimeImmutable $now, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT *
FROM upload_sessions
WHERE expires_at < :now
  AND status IN ('created', 'uploading', 'uploaded', 'failed')
ORDER BY expires_at ASC
LIMIT :limit
SQL,
            ['now' => $now->format(DATE_ATOM), 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): UploadSession => DbalUploadSessionMapper::fromRow($row),
            $rows,
        );
    }

    public function delete(UploadSession $session): void
    {
        $this->connection->delete('upload_sessions', ['id' => $session->id->toRfc4122()]);
    }
}
