<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use Doctrine\DBAL\Connection;
use Mediarama\Media\Infrastructure\Persistence\DbalMetadataWorkspaceKeywords;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DbalMetadataWorkspaceKeywordsTest extends TestCase
{
    private const OWNER = '74444444-4444-4444-8444-444444444441';
    private const MEDIA = '74444444-4444-4444-8444-444444444449';

    public function testAppendCreatesManualMembershipAndTouchesAsset(): void
    {
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['metadata' => '{}']);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([[
                'id' => '74444444-4444-4444-8444-444444444451',
                'name' => 'existing',
                'source' => 'manual',
            ]]);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(false, false);
        $connection->expects(self::once())
            ->method('insert')
            ->with('tags', self::callback(
                static fn (array $row): bool => $row['name'] === 'new keyword'
                    && $row['slug'] === 'new-keyword',
            ));
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO media_tags'),
                self::callback(
                    static fn (array $params): bool => $params['source'] === 'manual'
                        && $params['media'] === self::MEDIA,
                ),
            );
        $connection->expects(self::once())
            ->method('update')
            ->with('media_assets', self::arrayHasKey('updated_at'), ['id' => self::MEDIA]);

        (new DbalMetadataWorkspaceKeywords($connection))->update(
            Uuid::fromString(self::OWNER),
            Uuid::fromString(self::MEDIA),
            'append',
            ['  new keyword  ', 'NEW KEYWORD'],
        );
    }

    public function testRevertReplacesCurrentMembershipsWithEmbeddedSource(): void
    {
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['metadata' => json_encode([
                'iptc' => ['Keywords' => ['workspace', 'fixture']],
            ], JSON_THROW_ON_ERROR)]);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([[
                'id' => '74444444-4444-4444-8444-444444444451',
                'name' => 'manual-only',
                'source' => 'manual',
            ]]);
        $connection->expects(self::once())
            ->method('delete')
            ->with('media_tags', ['media_id' => self::MEDIA]);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                '74444444-4444-4444-8444-444444444452',
                '74444444-4444-4444-8444-444444444453',
            );
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO media_tags'),
                self::callback(
                    static fn (array $params): bool => $params['source'] === 'embedded',
                ),
            );
        $connection->expects(self::once())->method('update');

        (new DbalMetadataWorkspaceKeywords($connection))->update(
            Uuid::fromString(self::OWNER),
            Uuid::fromString(self::MEDIA),
            'revert',
            [],
        );
    }

    public function testRemoveDeletesOnlyRequestedCurrentNames(): void
    {
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['metadata' => '{}']);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => '74444444-4444-4444-8444-444444444451',
                    'name' => 'Keep Me',
                    'source' => 'manual',
                ],
                [
                    'id' => '74444444-4444-4444-8444-444444444452',
                    'name' => 'Remove Me',
                    'source' => 'coppermine',
                ],
            ]);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('DELETE FROM media_tags'),
                self::callback(
                    static fn (array $params): bool => $params['names'] === ['remove me'],
                ),
                self::anything(),
            );
        $connection->expects(self::once())->method('update');

        (new DbalMetadataWorkspaceKeywords($connection))->update(
            Uuid::fromString(self::OWNER),
            Uuid::fromString(self::MEDIA),
            'remove',
            ['REMOVE ME'],
        );
    }

    public function testReplaceCanDeliberatelyRestoreManualProvenance(): void
    {
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['metadata' => '{}']);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([[
                'id' => '74444444-4444-4444-8444-444444444451',
                'name' => 'same keyword',
                'source' => 'embedded',
            ]]);
        $connection->expects(self::once())->method('delete');
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn('74444444-4444-4444-8444-444444444451');
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO media_tags'),
                self::callback(
                    static fn (array $params): bool => $params['source'] === 'manual',
                ),
            );
        $connection->expects(self::once())->method('update');

        (new DbalMetadataWorkspaceKeywords($connection))->update(
            Uuid::fromString(self::OWNER),
            Uuid::fromString(self::MEDIA),
            'replace',
            ['same keyword'],
        );
    }

    public function testNoEffectiveAppendIsRejected(): void
    {
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['metadata' => '{}']);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([[
                'id' => '74444444-4444-4444-8444-444444444451',
                'name' => 'existing',
                'source' => 'manual',
            ]]);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('update');

        $this->expectException(\InvalidArgumentException::class);
        (new DbalMetadataWorkspaceKeywords($connection))->update(
            Uuid::fromString(self::OWNER),
            Uuid::fromString(self::MEDIA),
            'append',
            ['Existing'],
        );
    }

    public function testConflictingSourceKeywordSetsFailClosed(): void
    {
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['metadata' => json_encode([
                'xmp' => ['Subject' => ['one']],
                'iptc' => ['Keywords' => ['two']],
            ], JSON_THROW_ON_ERROR)]);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $connection->expects(self::never())->method('delete');
        $connection->expects(self::never())->method('update');

        $this->expectException(\InvalidArgumentException::class);
        (new DbalMetadataWorkspaceKeywords($connection))->update(
            Uuid::fromString(self::OWNER),
            Uuid::fromString(self::MEDIA),
            'revert',
            [],
        );
    }

    private function connection(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($connection),
        );

        return $connection;
    }
}
