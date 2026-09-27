<?php

declare(strict_types=1);

namespace Mediarama\Platform\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\PlatformSettings;
use Mediarama\Platform\Domain\SearchIndexPolicy;

final readonly class DbalPlatformSettingsRepository implements PlatformSettingsRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function current(): PlatformSettings
    {
        $row = $this->connection->fetchAssociative(
            'SELECT deployment_profile, public_publishing_enabled, search_index_default
             FROM platform_settings
             WHERE id = 1'
        );

        if ($row === false) {
            throw new \RuntimeException('Platform settings are not initialized.');
        }

        return new PlatformSettings(
            DeploymentProfile::from((string) $row['deployment_profile']),
            $this->toBoolean($row['public_publishing_enabled']),
            SearchIndexPolicy::from((string) $row['search_index_default']),
        );
    }

    public function applyProfile(DeploymentProfile $profile): PlatformSettings
    {
        $settings = PlatformSettings::forProfile($profile);
        $this->save($settings);

        return $settings;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower((string) $value),
            ['1', 't', 'true', 'yes', 'on'],
            true,
        );
    }

    public function save(PlatformSettings $settings): void
    {
        $updatedAt = (new DateTimeImmutable())->format(DATE_ATOM);

        $updated = $this->connection->executeStatement(
            <<<'SQL'
UPDATE platform_settings
SET deployment_profile = :profile,
    public_publishing_enabled = :publishing,
    search_index_default = :index_default,
    updated_at = :updated
WHERE id = 1
SQL,
            [
                'profile' => $settings->deploymentProfile->value,
                'publishing' => $settings->publicPublishingEnabled,
                'index_default' => $settings->searchIndexDefault->value,
                'updated' => $updatedAt,
            ],
        );

        if ($updated !== 1) {
            throw new \RuntimeException('Platform settings singleton is missing.');
        }
    }
}
