<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Export;

use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Domain\MetadataExportProfile;
use PHPUnit\Framework\TestCase;

final class MetadataExportPolicyTest extends TestCase
{
    public function testRejectsUnknownCustomFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported custom metadata field(s): made_up_field.');

        new MetadataExportPolicy(
            MetadataExportProfile::Custom,
            ['title', 'made_up_field'],
        );
    }

    public function testRejectsAssociativeCustomFieldInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom export fields must be a list.');

        new MetadataExportPolicy(
            MetadataExportProfile::Custom,
            ['field' => 'title'],
        );
    }

    public function testRejectsNonStringCustomFieldNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom export field names must be non-empty strings.');

        new MetadataExportPolicy(
            MetadataExportProfile::Custom,
            ['title', 123],
        );
    }

    public function testRejectsDuplicateCustomFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom export fields must not contain duplicates.');

        new MetadataExportPolicy(
            MetadataExportProfile::Custom,
            ['title', 'title'],
        );
    }

    public function testAllowsExplicitLocationAndCoordinateFields(): void
    {
        $policy = new MetadataExportPolicy(
            MetadataExportProfile::Custom,
            ['location_name', 'latitude', 'longitude'],
        );

        self::assertSame(
            ['location_name', 'latitude', 'longitude'],
            $policy->includedFields,
        );
    }
}
