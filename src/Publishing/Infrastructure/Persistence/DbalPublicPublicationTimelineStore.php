<?php

declare(strict_types=1);

namespace Mediarama\Publishing\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Publishing\Application\PublicPublicationTimelineStore;
use Mediarama\Publishing\Domain\PublicationOrigin;
use Mediarama\Publishing\Domain\PublicPublicationTimeline;
use Symfony\Component\Uid\Uuid;

final readonly class DbalPublicPublicationTimelineStore implements PublicPublicationTimelineStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function media(Uuid $mediaId): PublicPublicationTimeline
    {
        return $this->read('media_assets', $mediaId);
    }

    public function collection(Uuid $collectionId): PublicPublicationTimeline
    {
        return $this->read('collections', $collectionId);
    }

    public function recordMediaFirstPublication(
        Uuid $mediaId,
        DateTimeImmutable $publishedAt,
        PublicationOrigin $origin = PublicationOrigin::Editorial,
        ?string $source = null,
    ): PublicPublicationTimeline {
        return $this->recordFirstPublication(
            'media_assets',
            $mediaId,
            $publishedAt,
            $origin,
            $source,
        );
    }

    public function recordCollectionFirstPublication(
        Uuid $collectionId,
        DateTimeImmutable $publishedAt,
        PublicationOrigin $origin = PublicationOrigin::Editorial,
        ?string $source = null,
    ): PublicPublicationTimeline {
        return $this->recordFirstPublication(
            'collections',
            $collectionId,
            $publishedAt,
            $origin,
            $source,
        );
    }

    public function touchMediaPublicContent(
        Uuid $mediaId,
        DateTimeImmutable $changedAt,
    ): PublicPublicationTimeline {
        return $this->touch('media_assets', $mediaId, $changedAt);
    }

    public function touchCollectionPublicContent(
        Uuid $collectionId,
        DateTimeImmutable $changedAt,
    ): PublicPublicationTimeline {
        return $this->touch('collections', $collectionId, $changedAt);
    }

    private function recordFirstPublication(
        string $table,
        Uuid $id,
        DateTimeImmutable $publishedAt,
        PublicationOrigin $origin,
        ?string $source,
    ): PublicPublicationTimeline {
        $source = $this->validateProvenance($origin, $source);

        $affected = $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
UPDATE %s
SET public_published_origin = CASE
        WHEN public_published_at IS NULL THEN :origin
        ELSE public_published_origin
    END,
    public_published_source = CASE
        WHEN public_published_at IS NULL THEN :source
        ELSE public_published_source
    END,
    public_updated_at = CASE
        WHEN public_published_at IS NULL
            THEN GREATEST(COALESCE(public_updated_at, :published_at), :published_at)
        ELSE public_updated_at
    END,
    public_published_at = COALESCE(public_published_at, :published_at)
WHERE id = :id
SQL,
                $table,
            ),
            [
                'origin' => $origin->value,
                'source' => $source,
                'published_at' => $publishedAt->format(DATE_ATOM),
                'id' => $id->toRfc4122(),
            ],
        );

        if ($affected !== 1) {
            throw new \RuntimeException('Publication timeline target does not exist.');
        }

        return $this->read($table, $id);
    }

    private function touch(
        string $table,
        Uuid $id,
        DateTimeImmutable $changedAt,
    ): PublicPublicationTimeline {
        $affected = $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
UPDATE %s
SET public_updated_at = GREATEST(
        COALESCE(public_updated_at, :changed_at),
        :changed_at
    )
WHERE id = :id
SQL,
                $table,
            ),
            [
                'changed_at' => $changedAt->format(DATE_ATOM),
                'id' => $id->toRfc4122(),
            ],
        );

        if ($affected !== 1) {
            throw new \RuntimeException('Publication timeline target does not exist.');
        }

        return $this->read($table, $id);
    }

    private function read(string $table, Uuid $id): PublicPublicationTimeline
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                <<<'SQL'
SELECT
    public_published_at,
    public_updated_at,
    public_published_origin,
    public_published_source
FROM %s
WHERE id = :id
SQL,
                $table,
            ),
            ['id' => $id->toRfc4122()],
        );

        if ($row === false) {
            throw new \RuntimeException('Publication timeline target does not exist.');
        }

        return new PublicPublicationTimeline(
            $this->date($row['public_published_at'] ?? null),
            $this->date($row['public_updated_at'] ?? null),
            $row['public_published_origin'] !== null
                ? PublicationOrigin::from((string) $row['public_published_origin'])
                : null,
            $row['public_published_source'] !== null
                ? (string) $row['public_published_source']
                : null,
        );
    }

    private function validateProvenance(
        PublicationOrigin $origin,
        ?string $source,
    ): ?string {
        $source = $source !== null ? trim($source) : null;
        $source = $source === '' ? null : $source;

        if ($origin === PublicationOrigin::Imported && $source === null) {
            throw new \InvalidArgumentException(
                'Imported publication timestamps require an explicit source identifier.',
            );
        }

        if ($origin === PublicationOrigin::Editorial && $source !== null) {
            throw new \InvalidArgumentException(
                'Editorial publication timestamps must not carry an import source identifier.',
            );
        }

        if ($source !== null && strlen($source) > 255) {
            throw new \InvalidArgumentException(
                'Publication source identifier must not exceed 255 bytes.',
            );
        }

        return $source;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable((string) $value) : null;
    }
}
