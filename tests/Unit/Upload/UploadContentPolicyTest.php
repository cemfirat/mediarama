<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Upload;

use Mediarama\Media\Domain\MediaType;
use Mediarama\Upload\Application\InspectedContent;
use Mediarama\Upload\Application\UploadContentPolicy;
use Mediarama\Upload\Application\UploadProblem;
use Mediarama\Upload\Domain\UploadFailureStage;
use PHPUnit\Framework\TestCase;

final class UploadContentPolicyTest extends TestCase
{
    public function testAssertAllowedRejectsDocumentTypeEvenWhenMimeIsAllowlisted(): void
    {
        $policy = new UploadContentPolicy(['image/jpeg']);

        try {
            $policy->assertAllowed(new InspectedContent(
                'image/jpeg',
                MediaType::Document,
                str_repeat('a', 64),
                123,
            ));
            self::fail('Expected document media type to be rejected.');
        } catch (UploadProblem $error) {
            self::assertSame('media_type_not_allowed', $error->publicCode);
            self::assertFalse($error->retryable);
            self::assertTrue($error->terminal);
            self::assertSame(UploadFailureStage::Finalization, $error->failureStage);
        }
    }

    public function testAuditMimeOnlyEntryPointRetainsTerminalSafeContract(): void
    {
        $policy = new UploadContentPolicy(['image/jpeg']);

        try {
            $policy->assertMimeAllowed('application/pdf');
            self::fail('Expected disallowed MIME type to be rejected.');
        } catch (UploadProblem $error) {
            self::assertSame('media_type_not_allowed', $error->publicCode);
            self::assertFalse($error->retryable);
            self::assertTrue($error->terminal);
            self::assertSame(UploadFailureStage::Finalization, $error->failureStage);
        }
    }
}
