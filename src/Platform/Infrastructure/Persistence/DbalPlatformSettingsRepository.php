<?php

declare(strict_types=1);

namespace Mediarama\Platform\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\PlatformSettings;
use Mediarama\Platform\Domain\SearchIndexPolicy;

final readonly class DbalPlatformSettingsRepository implements PlatformSettingsRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(): PlatformSettings
    {
        $row = $this->connection->fetchAssociative(
            'SELECT public_publishing_enabled, search_index_default FROM platform_settings WHERE id = 1',
        );

        if ($row === false) {
            throw new \LogicException('Platform settings singleton is missing.');
        }

        return new PlatformSettings(
            publicPublishingEnabled: (bool) $row['public_publishing_enabled'],
            searchIndexDefault: SearchIndexPolicy::from((string) $row['search_index_default']),
        );
    }

    public function save(PlatformSettings $settings): void
    {
        $updated = $this->connection->executeStatement(
            <<<'SQL'
UPDATE platform_settings
SET public_publishing_enabled = :public_publishing,
    search_index_default = :search_default,
    updated_at = :updated_at
WHERE id = 1
SQL,
            [
                'public_publishing' => $settings->publicPublishingEnabled,
                'search_default' => $settings->searchIndexDefault->value,
                'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ],
        );

        if ($updated !== 1) {
            throw new \LogicException('Platform settings singleton could not be updated.');
        }
    }
}
