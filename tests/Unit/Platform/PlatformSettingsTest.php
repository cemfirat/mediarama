<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Platform;

use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\PlatformSettings;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use PHPUnit\Framework\TestCase;

final class PlatformSettingsTest extends TestCase
{
    public function testPrivateWorkspaceIsSafeByDefault(): void
    {
        $settings = PlatformSettings::forProfile(DeploymentProfile::PrivateWorkspace);

        self::assertFalse($settings->publicPublishingEnabled);
        self::assertSame(SearchIndexPolicy::NoIndex, $settings->searchIndexDefault);
    }

    public function testPublicPublishingEnablesIndexDefault(): void
    {
        $settings = PlatformSettings::forProfile(DeploymentProfile::PublicPublishing);

        self::assertTrue($settings->publicPublishingEnabled);
        self::assertSame(SearchIndexPolicy::Index, $settings->searchIndexDefault);
    }

    public function testInternalIsolatedIsFailClosed(): void
    {
        $settings = PlatformSettings::forProfile(DeploymentProfile::InternalIsolated);

        self::assertFalse($settings->publicPublishingEnabled);
        self::assertSame(SearchIndexPolicy::NoIndex, $settings->searchIndexDefault);
    }

    public function testResourcePolicyResolvesAgainstSiteDefault(): void
    {
        self::assertTrue(
            SearchIndexPolicy::Inherit->resolve(SearchIndexPolicy::Index),
        );
        self::assertFalse(
            SearchIndexPolicy::Inherit->resolve(SearchIndexPolicy::NoIndex),
        );
        self::assertTrue(
            SearchIndexPolicy::Index->resolve(SearchIndexPolicy::NoIndex),
        );
        self::assertFalse(
            SearchIndexPolicy::NoIndex->resolve(SearchIndexPolicy::Index),
        );
    }

    public function testSiteDefaultCannotInherit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PlatformSettings(
            DeploymentProfile::PrivateWorkspace,
            false,
            SearchIndexPolicy::Inherit,
        );
    }
}
