<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Media\Application\MetadataWorkspaceKeywords;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class MetadataKeywordBatchController extends AbstractController
{
    private const MAX_MEDIA = 200;

    public function __construct(
        private readonly MetadataWorkspaceKeywords $keywords,
        private readonly CurrentUser $currentUser,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route(
        '/library/metadata/keywords/preview',
        name: 'library_metadata_keywords_batch_preview',
        methods: ['POST'],
    )]
    public function preview(Request $request): Response
    {
        $user = $this->user();
        $payload = $request->request->all();
        $this->requireCsrf(
            'metadata_keyword_batch_preview',
            is_string($payload['_csrf_token'] ?? null)
                ? $payload['_csrf_token']
                : '',
        );

        try {
            $mediaIds = $this->mediaIds($payload['media_ids'] ?? null);
            $change = $this->change($payload);
            $preview = $this->keywords->previewMany(
                $user->id,
                $mediaIds,
                $change['action'],
                $change['values'],
            );
        } catch (\InvalidArgumentException|\DomainException) {
            return $this->redirectToRoute('library_home', [
                'metadata_batch_error' => 1,
            ]);
        }

        return $this->privateResponse($this->render(
            '@Mediarama/library/metadata/keyword_batch_preview.html.twig',
            [
                'preview' => $preview,
                'action' => $change['action'],
                'values' => $change['values'],
                'apply_csrf_token' => $this->csrf
                    ->getToken('metadata_keyword_batch_apply')
                    ->getValue(),
            ],
        ));
    }

    #[Route(
        '/library/metadata/keywords/apply',
        name: 'library_metadata_keywords_batch_apply',
        methods: ['POST'],
    )]
    public function apply(Request $request): Response
    {
        $user = $this->user();
        $payload = $request->request->all();
        $this->requireCsrf(
            'metadata_keyword_batch_apply',
            is_string($payload['_csrf_token'] ?? null)
                ? $payload['_csrf_token']
                : '',
        );

        try {
            $mediaIds = $this->mediaIds($payload['media_ids'] ?? null);
            $change = $this->change($payload);
            $changed = $this->keywords->updateMany(
                $user->id,
                $mediaIds,
                $change['action'],
                $change['values'],
            );
        } catch (\InvalidArgumentException|\DomainException) {
            return $this->redirectToRoute('library_home', [
                'metadata_batch_error' => 1,
            ]);
        }

        return $this->redirectToRoute('library_home', [
            'metadata_batch_saved' => $changed,
        ]);
    }

    /**
     * @param mixed $values
     * @return list<Uuid>
     */
    private function mediaIds(mixed $values): array
    {
        if (
            !is_array($values)
            || $values === []
            || count($values) > self::MAX_MEDIA
        ) {
            throw new \InvalidArgumentException(
                'Select 1-200 MediaAssets for keyword editing.',
            );
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    'Metadata keyword selection is invalid.',
                );
            }

            try {
                $id = Uuid::fromString($value);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException(
                    'Metadata keyword selection is invalid.',
                );
            }

            $ids[$id->toRfc4122()] = $id;
        }

        if (count($ids) !== count($values)) {
            throw new \InvalidArgumentException(
                'Metadata keyword selection must be unique.',
            );
        }

        return array_values($ids);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action:string,values:list<string>}
     */
    private function change(array $payload): array
    {
        $action = $payload['keyword_action'] ?? null;
        if (
            !is_string($action)
            || !in_array($action, ['replace', 'append', 'remove', 'clear'], true)
        ) {
            throw new \InvalidArgumentException(
                'Metadata keyword operation is invalid.',
            );
        }

        if ($action === 'clear') {
            return ['action' => $action, 'values' => []];
        }

        $raw = $payload['keyword_values'] ?? null;
        if (is_string($raw)) {
            $parts = preg_split('/\R/u', $raw);
            if ($parts === false) {
                throw new \InvalidArgumentException(
                    'Metadata keyword values are invalid.',
                );
            }
        } elseif (is_array($raw) && array_is_list($raw)) {
            $parts = $raw;
        } else {
            throw new \InvalidArgumentException(
                'Metadata keyword values are invalid.',
            );
        }

        $values = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                throw new \InvalidArgumentException(
                    'Metadata keyword values are invalid.',
                );
            }

            $part = trim($part);
            if ($part !== '') {
                $values[] = $part;
            }
        }

        if ($values === []) {
            throw new \InvalidArgumentException(
                'Keyword operation requires at least one non-blank keyword.',
            );
        }

        return ['action' => $action, 'values' => $values];
    }

    private function user(): \Mediarama\Security\Application\AuthenticatedUser
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
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
