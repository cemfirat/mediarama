<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Http\Support\InputBagValue;
use Mediarama\Media\Application\MetadataWorkspaceEditor;
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
                'saved' => InputBagValue::booleanOrDefault(
                    $request->query,
                    'saved',
                ),
                'edit_error' => InputBagValue::booleanOrDefault(
                    $request->query,
                    'edit_error',
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

    /**
     * @param array<string,mixed> $payload
     * @return array<string,?string>
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

            if ($action === 'clear') {
                $changes[$field] = null;
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

            $changes[$field] = $value;
        }

        if ($changes === []) {
            throw new \InvalidArgumentException(
                'Choose at least one metadata field to set or clear.',
            );
        }

        return $changes;
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
