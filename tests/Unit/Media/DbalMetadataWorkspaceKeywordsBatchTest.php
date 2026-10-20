<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use Doctrine\DBAL\Connection;
use Mediarama\Media\Infrastructure\Persistence\DbalMetadataWorkspaceKeywords;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DbalMetadataWorkspaceKeywordsBatchTest extends TestCase
{
    private const OWNER = '74444444-4444-4444-8444-444444444441';
    private const MEDIA_ONE = '74444444-4444-4444-8444-444444444449';
    private const MEDIA_TWO = '74444444-4444-4444-8444-444444444450';
    private const TAG = '74444444-4444-4444-8444-444444444451';

    public function testPreviewShowsThePerAssetKeywordResult(): void
    {
        $connection = $this->connection();
        $connection->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    [
                        'id' => self::MEDIA_ONE,
                        'original_filename' => 'one.jpg',
                        'title' => 'One',
                    ],
                    [
                        'id' => self::MEDIA_TWO,
                        'original_filename' => 'two.jpg',
                        'title' => null,
                    ],
                ],
                [[
                    'media_id' => self::MEDIA_TWO,
                    'id' => self::TAG,
                    'name' => 'Existing',
                    'source' => 'embedded',
                ]],
            );

        $preview = (new DbalMetadataWorkspaceKeywords($connection))->previewMany(
            Uuid::fromString(self::OWNER),
            [
                Uuid::fromString(self::MEDIA_ONE),
                Uuid::fromString(self::MEDIA_TWO),
            ],
            'append',
            ['New'],
        );

        self::assertSame([
            [
                'id' => self::MEDIA_ONE,
                'filename' => 'one.jpg',
                'title' => 'One',
                'current' => [],
                'result' => ['New'],
                'added' => ['New'],
                'removed' => [],
                'changed' => true,
            ],
            [
                'id' => self::MEDIA_TWO,
                'filename' => 'two.jpg',
                'title' => null,
                'current' => ['Existing'],
                'result' => ['Existing', 'New'],
                'added' => ['New'],
                'removed' => [],
                'changed' => true,
            ],
        ], $preview);
    }

    public function testAppendBatchPreservesExistingKeywordProvenance(): void
    {
        $connection = $this->connection();
        $connection->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    [
                        'id' => self::MEDIA_ONE,
                        'original_filename' => 'one.jpg',
                        'title' => null,
                    ],
                    [
                        'id' => self::MEDIA_TWO,
                        'original_filename' => 'two.jpg',
                        'title' => null,
                    ],
                ],
                [[
                    'media_id' => self::MEDIA_TWO,
                    'id' => self::TAG,
                    'name' => 'Existing',
                    'source' => 'coppermine',
                ]],
            );
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->with(
                self::stringContains('SELECT id'),
                ['name' => 'New'],
            )
            ->willReturn(self::TAG);
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO media_tags'),
                self::callback(
                    static fn (array $params): bool => in_array(
                        $params['media'],
                        [self::MEDIA_ONE, self::MEDIA_TWO],
                        true,
                    )
                        && $params['source'] === 'manual',
                ),
            );
        $connection->expects(self::never())->method('delete');
        $connection->expects(self::exactly(2))
            ->method('update')
            ->willReturnCallback(
                static function (
                    string $table,
                    array $data,
                    array $criteria,
                ): int {
                    self::assertSame('media_assets', $table);
                    self::assertArrayHasKey('updated_at', $data);
                    self::assertContains($criteria['id'], [self::MEDIA_ONE, self::MEDIA_TWO]);

                    return 1;
                },
            );

        $changed = (new DbalMetadataWorkspaceKeywords($connection))->updateMany(
            Uuid::fromString(self::OWNER),
            [
                Uuid::fromString(self::MEDIA_ONE),
                Uuid::fromString(self::MEDIA_TWO),
            ],
            'append',
            ['New'],
        );

        self::assertSame(2, $changed);
    }

    public function testMixedOwnershipRejectsTheWholeBatchBeforeReadingKeywords(): void
    {
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([[
                'id' => self::MEDIA_ONE,
                'original_filename' => 'one.jpg',
                'title' => null,
            ]]);
        $connection->expects(self::never())->method('update');
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\DomainException::class);
        (new DbalMetadataWorkspaceKeywords($connection))->updateMany(
            Uuid::fromString(self::OWNER),
            [
                Uuid::fromString(self::MEDIA_ONE),
                Uuid::fromString(self::MEDIA_TWO),
            ],
            'clear',
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
