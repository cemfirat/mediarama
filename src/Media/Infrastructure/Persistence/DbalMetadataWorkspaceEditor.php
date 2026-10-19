<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MetadataWorkspaceEditor;
use Mediarama\Media\Domain\CanonicalSourceMetadata;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MetadataProvenance;
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
        $prepared = $this->prepare($changes);

        $asset = $this->media->get($mediaId);
        if ($asset->ownerId === null || !$asset->ownerId->equals($ownerId)) {
            throw new \DomainException(
                'Metadata workspace may modify only requester-owned MediaAssets.',
            );
        }

        $resolved = [];
        foreach ($prepared as $field => $change) {
            $action = $change['action'];
            $value = $change['value'];
            $provenance = MetadataProvenance::User;

            if ($action === 'revert') {
                $source = CanonicalSourceMetadata::fromStoredSnapshot(
                    $asset->metadata,
                    $field,
                );
                if ($source === null) {
                    throw new \InvalidArgumentException(sprintf(
                        'Metadata field "%s" has no unambiguous source value to restore.',
                        $field,
                    ));
                }

                $value = $this->value(
                    $field,
                    $source['value'],
                    self::FIELD_LIMITS[$field],
                );
                $provenance = MetadataProvenance::Embedded;
            }

            $resolved[$field] = [
                'action' => $action,
                'value' => $value,
                'provenance' => $provenance,
            ];
        }

        $changed = false;
        $publicPresentationChanged = false;

        foreach ($resolved as $field => $change) {
            $current = $this->current($asset, $field);
            $valueChanged = $current !== $change['value'];
            $currentProvenance = isset($asset->metadataProvenance[$field])
                ? (string) $asset->metadataProvenance[$field]
                : null;
            $provenanceChanged = $currentProvenance !== $change['provenance']->value;

            if (
                !$valueChanged
                && ($change['action'] !== 'revert' || !$provenanceChanged)
            ) {
                continue;
            }

            $asset->editMetadata($field, $change['value']);
            if ($change['action'] === 'revert') {
                $asset->metadataProvenance[$field] = MetadataProvenance::Embedded->value;
            }
            $changed = true;

            if (
                $valueChanged
                && ($field === 'title' || $field === 'description')
            ) {
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

    /**
     * @param array<string,array{action:string,value:?string}> $changes
     * @return array<string,array{action:string,value:?string}>
     */
    private function prepare(array $changes): array
    {
        if ($changes === []) {
            throw new \InvalidArgumentException(
                'Choose at least one metadata field to set, clear or revert.',
            );
        }

        $prepared = [];
        foreach ($changes as $field => $change) {
            if (!is_string($field) || !array_key_exists($field, self::FIELD_LIMITS)) {
                throw new \InvalidArgumentException(
                    'Metadata workspace contains an unsupported editable field.',
                );
            }

            if (!is_array($change)) {
                throw new \InvalidArgumentException(
                    'Metadata workspace field operation is invalid.',
                );
            }

            $action = $change['action'] ?? null;
            if (!is_string($action) || !in_array($action, ['set', 'clear', 'revert'], true)) {
                throw new \InvalidArgumentException(
                    'Metadata workspace field operation is invalid.',
                );
            }

            $value = $change['value'] ?? null;
            if ($action === 'set') {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException(
                        'Metadata workspace field value is invalid.',
                    );
                }

                $value = $this->value(
                    $field,
                    $value,
                    self::FIELD_LIMITS[$field],
                );
            } elseif ($value !== null) {
                throw new \InvalidArgumentException(
                    'Clear and revert operations must not carry a field value.',
                );
            }

            $prepared[$field] = [
                'action' => $action,
                'value' => $action === 'clear' ? null : $value,
            ];
        }

        return $prepared;
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
