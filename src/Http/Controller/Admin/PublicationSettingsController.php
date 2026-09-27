<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Admin;

use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\PlatformSettings;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Security\Application\CurrentUser;
use Mediarama\Security\Application\SystemAdministrationPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PublicationSettingsController extends AbstractController
{
    private const CSRF_ID = 'admin_publication_settings';

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly SystemAdministrationPolicy $administration,
        private readonly PlatformSettingsRepository $settings,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route(
        '/admin/settings/publication',
        name: 'admin_publication_settings',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $user = $this->currentUser->requireUser();

        if (!$this->administration->canAdminister($user->id)) {
            throw $this->createAccessDeniedException(
                'System administration permission is required.',
            );
        }

        if ($request->isMethod('POST')) {
            $token = new CsrfToken(
                self::CSRF_ID,
                (string) $request->request->get('_csrf_token', ''),
            );

            if (!$this->csrf->isTokenValid($token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $action = (string) $request->request->get('action', '');

            if ($action === 'apply_profile') {
                $profile = DeploymentProfile::tryFrom(
                    (string) $request->request->get('profile', ''),
                );

                if ($profile === null) {
                    return $this->invalidRequest('Unknown deployment profile.');
                }

                $this->settings->applyProfile($profile);
            } elseif ($action === 'save_settings') {
                $current = $this->settings->current();
                $indexDefault = SearchIndexPolicy::tryFrom(
                    (string) $request->request->get('search_index_default', ''),
                );

                if (
                    $indexDefault === null
                    || $indexDefault === SearchIndexPolicy::Inherit
                ) {
                    return $this->invalidRequest(
                        'Site search-index default must be index or noindex.',
                    );
                }

                $this->settings->save(new PlatformSettings(
                    $current->deploymentProfile,
                    $request->request->getBoolean('public_publishing_enabled'),
                    $indexDefault,
                ));
            } else {
                return $this->invalidRequest('Unknown settings action.');
            }

            return $this->redirectToRoute('admin_publication_settings', [
                'saved' => '1',
            ]);
        }

        return $this->renderSettings(
            saved: $request->query->getBoolean('saved'),
        );
    }

    private function invalidRequest(string $message): Response
    {
        return $this->renderSettings(
            error: $message,
            status: Response::HTTP_BAD_REQUEST,
        );
    }

    private function renderSettings(
        bool $saved = false,
        ?string $error = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $response = $this->render(
            '@Mediarama/admin/settings/publication.html.twig',
            [
                'settings' => $this->settings->current(),
                'csrf_token' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
                'saved' => $saved,
                'error' => $error,
            ],
            new Response(status: $status),
        );

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
