<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Upload;

use Mediarama\Upload\Application\UploadPolicy;
use Mediarama\Upload\Domain\UploadProblem;
use PHPUnit\Framework\TestCase;

final class UploadPolicyTest extends TestCase
{
    public function testAcceptsValuesWithinLimits(): void
    {
        $policy = new UploadPolicy(1000, 100);
        $policy->assertAssetSize(1000);
        $policy->assertChunkSize(100);

        self::assertTrue(true);
    }

    public function testRejectsOversizedAsset(): void
    {
        try {
            (new UploadPolicy(1000, 100))->assertAssetSize(1001);
            self::fail('Expected oversized asset to be rejected.');
        } catch (UploadProblem $problem) {
            self::assertSame('upload_asset_size_invalid', $problem->errorCode);
            self::assertFalse($problem->retryable);
        }
    }

    public function testRejectsOversizedChunk(): void
    {
        try {
            (new UploadPolicy(1000, 100))->assertChunkSize(101);
            self::fail('Expected oversized chunk to be rejected.');
        } catch (UploadProblem $problem) {
            self::assertSame('upload_chunk_size_invalid', $problem->errorCode);
            self::assertTrue($problem->retryable);
        }
    }
}
