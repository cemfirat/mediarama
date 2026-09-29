<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Mediarama\Import\Application\ImportMappingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class CoppermineInteractionImporter
{
    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private Connection $target,
        private ImportMappingRepository $mappings,
        private CoppermineSourceKey $sourceKey,
        private CoppermineTablePrefix $prefix,
        private CoppermineFavoriteDecoder $favoriteDecoder,
    ) {
    }

    public function import(): CoppermineInteractionImportReport
    {
        $source = $this->sourceFactory->create();
        $warnings = [];

        $commentsImported = $this->importComments($source, $warnings);
        $favoritesImported = $this->importFavorites($source, $warnings);
        [$ratingsImported, $aggregates, $unrecoverable] = $this->importRatings($source, $warnings);

        return new CoppermineInteractionImportReport(
            $commentsImported,
            $favoritesImported,
            $ratingsImported,
            $aggregates,
            $unrecoverable,
            $warnings,
        );
    }

    /** @param list<string> $warnings */
    private function importComments(Connection $source, array &$warnings): int
    {
        $table = $source->quoteIdentifier($this->prefix->table('comments'));
        $rows = $source->fetchAllAssociative(
            'SELECT msg_id, pid, msg_author, msg_body, msg_date, author_id, approval, spam FROM '.$table.' ORDER BY msg_id ASC',
        );

        $count = 0;

        foreach ($rows as $row) {
            $sourceId = (string) $row['msg_id'];
            if ($this->mappings->findTargetId($this->sourceKey->value(), 'comment', $sourceId) !== null) {
                continue;
            }

            $mediaId = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', (string) $row['pid']);
            if ($mediaId === null) {
                $warnings[] = sprintf('Comment %s references unmapped picture %s.', $sourceId, $row['pid']);
                continue;
            }

            $userId = (int) $row['author_id'] > 0
                ? $this->mappings->findTargetId($this->sourceKey->value(), 'user', (string) $row['author_id'])
                : null;

            $moderationState = (string) $row['spam'] === 'YES'
                ? 'rejected'
                : ((string) $row['approval'] === 'YES' ? 'published' : 'pending_review');

            $commentId = Uuid::v7();
            $createdAt = $this->safeDate((string) $row['msg_date']);

            $this->target->insert('comments', [
                'id' => $commentId->toRfc4122(),
                'media_id' => $mediaId->toRfc4122(),
                'user_id' => $userId?->toRfc4122(),
                'guest_name' => $userId === null && trim((string) $row['msg_author']) !== ''
                    ? (string) $row['msg_author']
                    : null,
                'body' => (string) $row['msg_body'],
                'moderation_state' => $moderationState,
                'created_at' => $createdAt->format(DATE_ATOM),
                'updated_at' => $createdAt->format(DATE_ATOM),
                'deleted_at' => null,
            ]);

            $this->mappings->remember($this->sourceKey->value(), 'comment', $sourceId, $commentId);
            ++$count;
        }

        return $count;
    }

    /** @param list<string> $warnings */
    private function importFavorites(Connection $source, array &$warnings): int
    {
        $schema = $source->createSchemaManager();
        $tableName = $this->prefix->table('favpics');

        if (!in_array($tableName, $schema->listTableNames(), true)) {
            return 0;
        }

        $table = $source->quoteIdentifier($tableName);
        $rows = $source->fetchAllAssociative(
            'SELECT user_id, user_favpics FROM '.$table." WHERE user_favpics <> '' ORDER BY user_id ASC",
        );

        $count = 0;

        foreach ($rows as $row) {
            $sourceUserId = (string) $row['user_id'];
            $userId = $this->mappings->findTargetId($this->sourceKey->value(), 'user', $sourceUserId);

            if ($userId === null) {
                $warnings[] = sprintf('Favorites for user %s could not resolve the user.', $sourceUserId);
                continue;
            }

            $pictureIds = $this->favoriteDecoder->decode((string) $row['user_favpics']);

            if ($pictureIds === [] && trim((string) $row['user_favpics']) !== '') {
                $warnings[] = sprintf('Favorites for user %s could not be decoded safely.', $sourceUserId);
                continue;
            }

            foreach ($pictureIds as $sourcePictureId) {
                $mediaId = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', $sourcePictureId);

                if ($mediaId === null) {
                    $warnings[] = sprintf(
                        'Favorite for user %s references unmapped picture %s.',
                        $sourceUserId,
                        $sourcePictureId,
                    );
                    continue;
                }

                $count += $this->target->executeStatement(
                    <<<'SQL'
INSERT INTO favorites (user_id, media_id, created_at)
VALUES (:user_id, :media_id, NOW())
ON CONFLICT (user_id, media_id) DO NOTHING
SQL,
                    [
                        'user_id' => $userId->toRfc4122(),
                        'media_id' => $mediaId->toRfc4122(),
                    ],
                );
            }
        }

        return $count;
    }

    /**
     * @param list<string> $warnings
     * @return array{0:int,1:int,2:int}
     */
    private function importRatings(Connection $source, array &$warnings): array
    {
        $pictures = $source->quoteIdentifier($this->prefix->table('pictures'));
        $voteStats = $source->quoteIdentifier($this->prefix->table('vote_stats'));
        $config = $source->quoteIdentifier($this->prefix->table('config'));

        $oldStyle = (int) ($source->fetchOne(
            'SELECT value FROM '.$config." WHERE name = 'old_style_rating'",
        ) ?: 0);
        $configuredStars = (int) ($source->fetchOne(
            'SELECT value FROM '.$config." WHERE name = 'rating_stars_amount'",
        ) ?: 5);
        $sourceStars = $oldStyle !== 0 ? 5 : max(1, $configuredStars);

        $aggregates = 0;
        $unrecoverableVotes = 0;

        $pictureRows = $source->fetchAllAssociative(
            'SELECT pid, pic_rating, votes FROM '.$pictures.' WHERE votes > 0 ORDER BY pid ASC',
        );

        foreach ($pictureRows as $row) {
            $mediaId = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', (string) $row['pid']);
            if ($mediaId === null) {
                $warnings[] = sprintf('Rating aggregate references unmapped picture %s.', $row['pid']);
                continue;
            }

            $average5 = max(0.0, min(5.0, ((int) $row['pic_rating']) / 2000.0));

            $this->target->executeStatement(
                <<<'SQL'
INSERT INTO import_rating_aggregates (source, media_id, average_5, vote_count, imported_at)
VALUES ('coppermine', :media_id, :average_5, :vote_count, NOW())
ON CONFLICT (source, media_id)
DO UPDATE SET average_5 = EXCLUDED.average_5,
              vote_count = EXCLUDED.vote_count,
              imported_at = NOW()
SQL,
                [
                    'media_id' => $mediaId->toRfc4122(),
                    'average_5' => $average5,
                    'vote_count' => (int) $row['votes'],
                ],
            );
            ++$aggregates;
        }

        $ratingsImported = 0;
        $detailedByPicture = [];

        $rows = $source->fetchAllAssociative(
            'SELECT sid, pid, rating, sdate, uid FROM '.$voteStats.' WHERE uid > 0 AND rating > 0 ORDER BY sdate ASC, sid ASC',
        );

        foreach ($rows as $row) {
            $mediaId = $this->mappings->findTargetId($this->sourceKey->value(), 'picture', (string) $row['pid']);
            $userId = $this->mappings->findTargetId($this->sourceKey->value(), 'user', (string) $row['uid']);

            if ($mediaId === null || $userId === null) {
                $warnings[] = sprintf(
                    'Detailed vote %s could not resolve picture %s and user %s.',
                    $row['sid'],
                    $row['pid'],
                    $row['uid'],
                );
                continue;
            }

            $normalized = (int) round(((int) $row['rating']) * 5 / $sourceStars);
            $normalized = max(1, min(5, $normalized));
            $createdAt = (int) $row['sdate'] > 0
                ? (new \DateTimeImmutable('@'.(int) $row['sdate']))->setTimezone(new \DateTimeZone('UTC'))
                : new \DateTimeImmutable();

            $this->target->executeStatement(
                <<<'SQL'
INSERT INTO ratings (user_id, media_id, value, created_at, updated_at)
VALUES (:user_id, :media_id, :value, :created_at, :created_at)
ON CONFLICT (user_id, media_id)
DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
SQL,
                [
                    'user_id' => $userId->toRfc4122(),
                    'media_id' => $mediaId->toRfc4122(),
                    'value' => $normalized,
                    'created_at' => $createdAt->format(DATE_ATOM),
                ],
            );

            $detailedByPicture[(string) $row['pid']] = ($detailedByPicture[(string) $row['pid']] ?? 0) + 1;
            ++$ratingsImported;
        }

        foreach ($pictureRows as $row) {
            $sourceVotes = (int) $row['votes'];
            $recoverable = $detailedByPicture[(string) $row['pid']] ?? 0;
            $unrecoverableVotes += max(0, $sourceVotes - $recoverable);
        }

        return [$ratingsImported, $aggregates, $unrecoverableVotes];
    }

    private function safeDate(string $value): \DateTimeImmutable
    {
        if ($value === '' || str_starts_with($value, '1000-')) {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }
}
