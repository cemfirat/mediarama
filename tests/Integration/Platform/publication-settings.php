<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Platform\Infrastructure\Persistence\DbalPlatformSettingsRepository;

function requirePublicationSetting(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);

$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$settings = new DbalPlatformSettingsRepository($db);

try {
    $initial = $settings->current();
    requirePublicationSetting(
        $initial->deploymentProfile === DeploymentProfile::PrivateWorkspace,
        'empty/new installation migrates to Private workspace',
    );
    requirePublicationSetting(
        !$initial->publicPublishingEnabled,
        'new installation public publishing is disabled',
    );
    requirePublicationSetting(
        $initial->searchIndexDefault === SearchIndexPolicy::NoIndex,
        'new installation search-index default is noindex',
    );

    $public = $settings->applyProfile(DeploymentProfile::PublicPublishing);
    requirePublicationSetting(
        $public->publicPublishingEnabled
        && $public->searchIndexDefault === SearchIndexPolicy::Index,
        'Public publishing preset enables deliberate public discovery defaults',
    );

    $isolated = $settings->applyProfile(DeploymentProfile::InternalIsolated);
    requirePublicationSetting(
        !$isolated->publicPublishingEnabled
        && $isolated->searchIndexDefault === SearchIndexPolicy::NoIndex,
        'Internal / isolated preset fails closed for public discovery',
    );

    $settings->applyProfile(DeploymentProfile::PrivateWorkspace);
    requirePublicationSetting(
        $settings->current()->deploymentProfile === DeploymentProfile::PrivateWorkspace,
        'test restores recommended Private workspace default',
    );
} finally {
    $db->close();
}
