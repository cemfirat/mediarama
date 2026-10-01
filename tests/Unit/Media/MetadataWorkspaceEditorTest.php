<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\ModerationState;
use Mediarama\Media\Domain\ProcessingState;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Persistence\DbalMetadataWorkspaceEditor;
use Mediarama\Publishing\Application\PublicPublicationTimelineStore;
use Mediarama\Publishing\Domain\PublicPublicationTimeline;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class MetadataWorkspaceEditorTest extends TestCase
{
    public function testOwnerCanSetAndExplicitlyClearCanonicalMetadata(): void
    {
        $owner = Uuid::fromString('75555555-5555-4555-8555-555555555551');
        $asset = $this->media($owner);
        $source = $asset->metadata;

        $repository = $this->repository($asset);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $timeline = $this->createMock(PublicPublicationTimelineStore::class);
        $timeline->expects(self::never())->method('touchMediaPublicContent');

        $editor = new DbalMetadataWorkspaceEditor(
            $connection,
            $repository,
            $timeline,
        );

        $editor->update(
            $owner,
            $asset->id,
            [
                'creator' => null,
                'copyright' => '  Updated rights  ',
                'location_name' => '  Vienna  ',
            ],
        );

        self::assertNull($asset->creator);
        self::assertSame('Updated rights', $asset->copyright);
        self::assertSame('Vienna', $asset->locationName);
        self::assertSame('user', $asset->metadataProvenance['creator']);
        self::assertSame('user', $asset->metadataProvenance['copyright']);
        self::assertSame('user', $asset->metadataProvenance['location_name']);
        self::assertSame($source, $asset->metadata);
        self::assertSame(1, $repository->saveCalls);
    }

    public function testPublicPresentationEditTouchesTruthfulPublicTimeline(): void
    {
        $owner = Uuid::fromString('75555555-5555-4555-8555-555555555551');
        $asset = $this->media($owner);
        $repository = $this->repository($asset);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(true);

        $timeline = $this->createMock(PublicPublicationTimelineStore::class);
        $timeline->expects(self::once())
            ->method('touchMediaPublicContent')
            ->with(self::equalTo($asset->id), self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(new PublicPublicationTimeline(null, null, null, null));

        $editor = new DbalMetadataWorkspaceEditor(
            $connection,
            $repository,
            $timeline,
        );

        $editor->update(
            $owner,
            $asset->id,
            ['title' => 'Updated public title'],
        );

        self::assertSame('Updated public title', $asset->title);
        self::assertSame('user', $asset->metadataProvenance['title']);
        self::assertSame(1, $repository->saveCalls);
    }

    public function testBlankSetValueIsRejectedInsteadOfBecomingAnImplicitClear(): void
    {
        $owner = Uuid::fromString('75555555-5555-4555-8555-555555555551');
        $asset = $this->media($owner);

        $editor = new DbalMetadataWorkspaceEditor(
            $this->createMock(Connection::class),
            $this->repository($asset),
            $this->createMock(PublicPublicationTimelineStore::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $editor->update(
            $owner,
            $asset->id,
            ['creator' => '   '],
        );
    }

    public function testNonOwnerCannotModifyCanonicalMetadata(): void
    {
        $owner = Uuid::fromString('75555555-5555-4555-8555-555555555551');
        $asset = $this->media($owner);

        $editor = new DbalMetadataWorkspaceEditor(
            $this->createMock(Connection::class),
            $this->repository($asset),
            $this->createMock(PublicPublicationTimelineStore::class),
        );

        $this->expectException(\DomainException::class);
        $editor->update(
            Uuid::fromString('75555555-5555-4555-8555-555555555552'),
            $asset->id,
            ['title' => 'Forbidden edit'],
        );
    }

    private function media(Uuid $owner): MediaAsset
    {
        return MediaAsset::reconstitute(
            id: Uuid::fromString('75555555-5555-4555-8555-555555555559'),
            ownerId: $owner,
            original: new StorageObjectId(
                'media',
                'PRIVATE_STORAGE_SENTINEL/source.jpg',
            ),
            originalFilename: 'source.jpg',
            mimeType: 'image/jpeg',
            mediaType: MediaType::Image,
            byteSize: 1234,
            checksumSha256: str_repeat('b', 64),
            processingState: ProcessingState::Ready,
            moderationState: ModerationState::Draft,
            createdAt: new DateTimeImmutable('2026-10-01T07:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-10-01T07:00:00+00:00'),
            title: 'Current title',
            description: 'Current description',
            metadata: [
                'xmp' => [
                    'Title' => 'Immutable source title',
                    'Creator' => 'Immutable source creator',
                ],
            ],
            metadataProvenance: [
                'title' => 'embedded',
                'description' => 'embedded',
                'creator' => 'embedded',
            ],
            creator: 'Source creator',
            copyright: 'Original rights',
            locationName: 'Original place',
        );
    }

    private function repository(MediaAsset $asset): MediaAssetRepository
    {
        return new class($asset) implements MediaAssetRepository {
            public int $saveCalls = 0;

            public function __construct(private MediaAsset $asset)
            {
            }

            public function save(MediaAsset $media): void
            {
                $this->asset = $media;
                ++$this->saveCalls;
            }

            public function get(Uuid $id): MediaAsset
            {
                if (!$id->equals($this->asset->id)) {
                    throw new \DomainException('Media asset not found.');
                }

                return $this->asset;
            }
        };
    }
}
