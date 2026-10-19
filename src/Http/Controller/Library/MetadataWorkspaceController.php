<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Http\Support\InputBagValue;
use Mediarama\Media\Application\MetadataWorkspaceEditor;
use Mediarama\Media\Application\MetadataWorkspaceKeywords;
use Mediarama\Media\Application\MetadataWorkspaceReader;
use Mediarama\Security\Application\AuthenticatedUser;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class MetadataWorkspaceController extends AbstractController
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'title',
        'description',
        'creator',
        'copyright',
        'location_name',
    ];

    public function __construct(
        private readonly MetadataWorkspaceReader $workspace,
        private readonly MetadataWorkspaceEditor $editor,
        private readonly MetadataWorkspaceKeywords $keywords,
        private readonly CurrentUser $currentUser,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route(
        '/library/media/{id}/metadata',
        name: 'library_media_metadata_workspace',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id, Request $request): Response
    {
        $user = $this->user();
        $mediaId = $this->id($id);
        $workspace = $this->read($user, $mediaId);

        return $this->privateResponse($this->render(
            '@Mediarama/library/metadata/workspace.html.twig',
            [
                'workspace' => $workspace,
                'edit_csrf_token' => $this->csrf
                    ->getToken(
                        'metadata_workspace_update_'.$mediaId->toRfc4122(),
                    )
                    ->getValue(),
                'keyword_csrf_token' => $this->csrf
                    ->getToken(
                        'metadata_workspace_keywords_'.$mediaId->toRfc4122(),
                    )
                    ->getValue(),
                'saved' => InputBagValue::booleanOrDefault(
                    $request->query,
                    'saved',
                ),
                'edit_error' => InputBagValue::booleanOrDefault(
                    $request->query,
                    'edit_error',
                ),
                'keywords_saved' => InputBagValue::booleanOrDefault(
                    $request->query,
                    'keywords_saved',
                ),
                'keywords_error' => InputBagValue::booleanOrDefault(
                    $request->query,
                    'keywords_error',
                ),
            ],
        ));
    }

    #[Route(
        '/library/media/{id}/metadata/edit',
        name: 'library_media_metadata_workspace_update',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function update(string $id, Request $request): Response
    {
        $user = $this->user();
        $mediaId = $this->id($id);

        // Preserve the owner-only raw-metadata boundary before checking the
        // mutation token so another authenticated actor cannot probe targets.
        $this->read($user, $mediaId);

        $payload = $request->request->all();
        $token = $payload['_csrf_token'] ?? '';
        $this->requireCsrf(
            'metadata_workspace_update_'.$mediaId->toRfc4122(),
            is_string($token) ? $token : '',
        );

        try {
            $this->editor->update(
                $user->id,
                $mediaId,
                $this->changes($payload),
            );
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute(
                'library_media_metadata_workspace',
                [
                    'id' => $mediaId->toRfc4122(),
                    'edit_error' => 1,
                ],
            );
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute(
            'library_media_metadata_workspace',
            [
                'id' => $mediaId->toRfc4122(),
                'saved' => 1,
            ],
        );
    }

    #[Route(
        '/library/media/{id}/metadata/keywords',
        name: 'library_media_metadata_workspace_keywords',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function updateKeywords(string $id, Request $request): Response
    {
        $user = $this->user();
        $mediaId = $this->id($id);

        $this->read($user, $mediaId);

        $payload = $request->request->all();
        $token = $payload['_csrf_token'] ?? '';
        $this->requireCsrf(
            'metadata_workspace_keywords_'.$mediaId->toRfc4122(),
            is_string($token) ? $token : '',
        );

        try {
            $change = $this->keywordChange($payload);
            $this->keywords->update(
                $user->id,
                $mediaId,
                $change['action'],
                $change['values'],
            );
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute(
                'library_media_metadata_workspace',
                [
                    'id' => $mediaId->toRfc4122(),
                    'keywords_error' => 1,
                ],
            );
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute(
            'library_media_metadata_workspace',
            [
                'id' => $mediaId->toRfc4122(),
                'keywords_saved' => 1,
            ],
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array{action:string,value:?string}>
     */
    private function changes(array $payload): array
    {
        $actions = $payload['actions'] ?? [];
        $values = $payload['values'] ?? [];

        if (!is_array($actions) || !is_array($values)) {
            throw new \InvalidArgumentException(
                'Metadata workspace edit payload is invalid.',
            );
        }

        $changes = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            $action = $actions[$field] ?? 'keep';
            if (!is_string($action)) {
                throw new \InvalidArgumentException(
                    'Metadata workspace edit action is invalid.',
                );
            }

            if ($action === 'keep') {
                continue;
            }

            if ($action === 'clear' || $action === 'revert') {
                $changes[$field] = [
                    'action' => $action,
                    'value' => null,
                ];
                continue;
            }

            if ($action !== 'set') {
                throw new \InvalidArgumentException(
                    'Metadata workspace edit action is invalid.',
                );
            }

            $value = $values[$field] ?? null;
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    'Metadata workspace edit value is invalid.',
                );
            }

            $changes[$field] = [
                'action' => 'set',
                'value' => $value,
            ];
        }

        if ($changes === []) {
            throw new \InvalidArgumentException(
                'Choose at least one metadata field to set, clear or revert.',
            );
        }

        return $changes;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action:string,values:list<string>}
     */
    private function keywordChange(array $payload): array
    {
        $action = $payload['keyword_action'] ?? null;
        if (
            !is_string($action)
            || !in_array($action, ['replace', 'append', 'remove', 'clear', 'revert'], true)
        ) {
            throw new \InvalidArgumentException(
                'Metadata keyword operation is invalid.',
            );
        }

        if ($action === 'clear' || $action === 'revert') {
            return ['action' => $action, 'values' => []];
        }

        $raw = $payload['keyword_values'] ?? null;
        if (!is_string($raw)) {
            throw new \InvalidArgumentException(
                'Metadata keyword values are invalid.',
            );
        }

        $parts = preg_split('/\R/u', $raw);
        if ($parts === false) {
            throw new \InvalidArgumentException(
                'Metadata keyword values are invalid.',
            );
        }

        $values = array_values(array_filter(
            array_map(
                static fn (string $value): string => trim($value),
                $parts,
            ),
            static fn (string $value): bool => $value !== '',
        ));

        if ($values === []) {
            throw new \InvalidArgumentException(
                'Keyword operation requires at least one non-blank keyword.',
            );
        }

        return ['action' => $action, 'values' => $values];
    }

    private function read(
        AuthenticatedUser $user,
        Uuid $mediaId,
    ): \Mediarama\Media\Application\MetadataWorkspaceView {
        try {
            return $this->workspace->read($user->id, $mediaId);
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }
    }

    private function id(string $value): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }

    private function user(): AuthenticatedUser
    {
        try {
            return $this->currentUser->requireUser();
        } catch (\DomainException) {
            throw $this->createAccessDeniedException(
                'Authentication is required.',
            );
        }
    }

    private function requireCsrf(string $id, string $value): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($id, $value))) {
            throw $this->createAccessDeniedException(
                'Invalid CSRF token.',
            );
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
