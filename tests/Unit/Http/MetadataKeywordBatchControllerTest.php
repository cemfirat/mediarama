<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Http;

use Mediarama\Http\Controller\Library\MetadataKeywordBatchController;
use Mediarama\Media\Application\MetadataWorkspaceKeywords;
use Mediarama\Security\Application\CurrentUser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MetadataKeywordBatchControllerTest extends TestCase
{
    public function testConfirmationAcceptsExplicitKeywordArray(): void
    {
        self::assertSame(
            ['action' => 'replace', 'values' => ['Vienna, Austria', 'night']],
            $this->change([
                'keyword_action' => 'replace',
                'keyword_values' => [' Vienna, Austria ', 'night'],
            ]),
        );
    }

    public function testClearDoesNotUseClientSuppliedKeywords(): void
    {
        self::assertSame(
            ['action' => 'clear', 'values' => []],
            $this->change([
                'keyword_action' => 'clear',
                'keyword_values' => ['client-supplied'],
            ]),
        );
    }

    public function testBlankAppendIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->change([
            'keyword_action' => 'append',
            'keyword_values' => " \n ",
        ]);
    }

    public function testDuplicateAssetSelectionIsRejected(): void
    {
        $id = '74444444-4444-4444-8444-444444444449';
        $this->expectException(\InvalidArgumentException::class);
        $this->mediaIds([$id, $id]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action:string,values:list<string>}
     */
    private function change(array $payload): array
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'change');

        /** @var array{action:string,values:list<string>} $change */
        $change = $method->invoke($controller, $payload);

        return $change;
    }

    /** @param list<string> $values */
    private function mediaIds(array $values): array
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'mediaIds');

        return $method->invoke($controller, $values);
    }

    private function controller(): MetadataKeywordBatchController
    {
        return new MetadataKeywordBatchController(
            $this->createMock(MetadataWorkspaceKeywords::class),
            $this->createMock(CurrentUser::class),
            $this->createMock(CsrfTokenManagerInterface::class),
        );
    }
}
