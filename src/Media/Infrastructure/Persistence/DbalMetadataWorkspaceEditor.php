<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MetadataWorkspaceEditor;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Publishing\Application\PublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

final readonly class DbalMetadataWorkspaceEditor implements MetadataWorkspaceEditor
{
    /** @var array<string,int> */
    private const FIELD_LIMITS = [
        'title' => 200,
        'description' => 5000,
        'creator' => 500,
        'copyright' => 1000,
        'location_name' => 1000,
    ];

    public function __construct(
        private Connection $connection,
        private MediaAssetRepository $media,
        private PublicPublicationTimelineStore $timeline,
    ) {
    }

    public function update(
        Uuid $ownerId,
        Uuid $mediaId,
        array $changes,
    ): void {
        if ($changes === []) {
            throw new \InvalidArgumentException(
                'Choose at least one metadata field to set or clear.',
            );
        }

        $normalized = [];
        foreach ($changes as $field => $value) {
            if (!is_string($field) || !array_key_exists($field, self::FIELD_LIMITS)) {
                throw new \InvalidArgumentException(
                    'Metadata workspace contains an unsupported editable field.',
                );
            }

            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException(
                    'Metadata workspace field value is invalid.',
                );
            }

            $normalized[$field] = $this->value(
                $field,
                $value,
                self::FIELD_LIMITS[$field],
            );
        }

        $asset = $this->media->get($mediaId);
        if ($asset->ownerId === null || !$asset->ownerId->equals($ownerId)) {
            throw new \DomainException(
                'Metadata workspace may modify only requester-owned MediaAssets.',
            );
        }

        $changed = false;
        $publicPresentationChanged = false;

        foreach ($normalized as $field => $value) {
            if ($this->current($asset, $field) === $value) {
                continue;
            }

            $asset->editMetadata($field, $value);
            $changed = true;

            if ($field === 'title' || $field === 'description') {
                $publicPresentationChanged = true;
            }
        }

        if (!$changed) {
            throw new \InvalidArgumentException(
                'Metadata workspace update contains no effective change.',
            );
        }

        $this->media->save($asset);

        if ($publicPresentationChanged && $this->isPublic($mediaId)) {
            $this->timeline->touchMediaPublicContent(
                $mediaId,
                new DateTimeImmutable(),
            );
        }
    }

    private function value(
        string $field,
        ?string $value,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');

        if ($value === '' || $length === false || $length > $maximumLength) {
            throw new \InvalidArgumentException(sprintf(
                'Metadata field "%s" must contain 1-%d characters when set.',
                $field,
                $maximumLength,
            ));
        }

        return $value;
    }

    private function current(MediaAsset $asset, string $field): ?string
    {
        return match ($field) {
            'title' => $asset->title,
            'description' => $asset->description,
            'creator' => $asset->creator,
            'copyright' => $asset->copyright,
            'location_name' => $asset->locationName,
            default => throw new \InvalidArgumentException(
                'Metadata workspace contains an unsupported editable field.',
            ),
        };
    }

    private function isPublic(Uuid $mediaId): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM media_assets m
    WHERE m.id = :media
      AND m.deleted_at IS NULL
      AND m.processing_state = 'ready'
      AND m.moderation_state = 'published'
      AND EXISTS (
          SELECT 1
          FROM collection_media membership
          JOIN effective_public_collections visible
            ON visible.collection_id = membership.collection_id
          WHERE membership.media_id = m.id
      )
)
SQL,
            ['media' => $mediaId->toRfc4122()],
        );
    }
}
