<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Http;

use Mediarama\Http\Controller\Library\MetadataWorkspaceController;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MetadataWorkspaceEditor;
use Mediarama\Media\Application\MetadataWorkspaceKeywords;
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

    public function testKeywordValuesAreSplitOnlyOnLines(): void
    {
        self::assertSame([
            'action' => 'append',
            'values' => ['Vienna, Austria', 'portrait', 'night'],
        ], $this->keywordChange([
            'keyword_action' => 'append',
            'keyword_values' => " Vienna, Austria \nportrait\r\n\n night ",
        ]));
    }

    public function testKeywordRevertIgnoresClientSuppliedValues(): void
    {
        self::assertSame([
            'action' => 'revert',
            'values' => [],
        ], $this->keywordChange([
            'keyword_action' => 'revert',
            'keyword_values' => 'CLIENT_SUPPLIED_KEYWORD_SENTINEL',
        ]));
    }

    public function testUnknownKeywordActionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->keywordChange([
            'keyword_action' => 'copy_raw_namespace',
            'keyword_values' => 'value',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array{action:string,value:?string}>
     */
    private function changes(array $payload): array
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'changes');

        /** @var array<string,array{action:string,value:?string}> $changes */
        $changes = $method->invoke($controller, $payload);

        return $changes;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action:string,values:list<string>}
     */
    private function keywordChange(array $payload): array
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'keywordChange');

        /** @var array{action:string,values:list<string>} $change */
        $change = $method->invoke($controller, $payload);

        return $change;
    }

    private function controller(): MetadataWorkspaceController
    {
        $keywords = $this->createMock(MetadataWorkspaceKeywords::class);

        return new MetadataWorkspaceController(
            new MetadataWorkspaceReader(
                $this->createMock(MediaAssetRepository::class),
                $keywords,
            ),
            $this->createMock(MetadataWorkspaceEditor::class),
            $keywords,
            $this->createMock(CurrentUser::class),
            $this->createMock(CsrfTokenManagerInterface::class),
        );
    }
}
