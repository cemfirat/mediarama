<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Media\Application\MetadataWorkspaceReader;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class MetadataWorkspaceController extends AbstractController
{
    public function __construct(
        private readonly MetadataWorkspaceReader $workspace,
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route(
        '/library/media/{id}/metadata',
        name: 'library_media_metadata_workspace',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id): Response
    {
        try {
            $user = $this->currentUser->requireUser();
        } catch (\DomainException) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        try {
            $mediaId = Uuid::fromString($id);
            $workspace = $this->workspace->read($user->id, $mediaId);
        } catch (\InvalidArgumentException|\DomainException) {
            throw $this->createNotFoundException();
        }

        $response = $this->render(
            '@Mediarama/library/metadata/workspace.html.twig',
            ['workspace' => $workspace],
        );
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
