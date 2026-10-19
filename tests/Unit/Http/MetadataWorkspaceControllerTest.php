<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Http;

use Mediarama\Http\Controller\Library\MetadataWorkspaceController;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MetadataWorkspaceEditor;
use Mediarama\Media\Application\MetadataWorkspaceReader;
use Mediarama\Security\Application\CurrentUser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MetadataWorkspaceControllerTest extends TestCase
{
    public function testRevertActionNeverAcceptsAClientSuppliedSourceValue(): void
    {
        $changes = $this->changes([
            'actions' => [
                'title' => 'revert',
                'description' => 'set',
                'creator' => 'clear',
            ],
            'values' => [
                'title' => 'CLIENT_SUPPLIED_SOURCE_SENTINEL',
                'description' => 'Updated description',
            ],
        ]);

        self::assertSame([
            'title' => ['action' => 'revert', 'value' => null],
            'description' => ['action' => 'set', 'value' => 'Updated description'],
            'creator' => ['action' => 'clear', 'value' => null],
        ], $changes);
    }

    public function testUnknownEditActionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->changes([
            'actions' => ['title' => 'copy_arbitrary_raw_tag'],
            'values' => [],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array{action:string,value:?string}>
     */
    private function changes(array $payload): array
    {
        $controller = new MetadataWorkspaceController(
            new MetadataWorkspaceReader(
                $this->createMock(MediaAssetRepository::class),
            ),
            $this->createMock(MetadataWorkspaceEditor::class),
            $this->createMock(CurrentUser::class),
            $this->createMock(CsrfTokenManagerInterface::class),
        );

        $method = new ReflectionMethod($controller, 'changes');

        /** @var array<string,array{action:string,value:?string}> $changes */
        $changes = $method->invoke($controller, $payload);

        return $changes;
    }
}
