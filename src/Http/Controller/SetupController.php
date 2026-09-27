<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Platform\Application\FirstRunSetup;
use Mediarama\Platform\Application\SetupUnavailableException;
use Mediarama\Platform\Application\SetupValidationException;
use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\SetupCompletionMethod;
use Mediarama\Platform\Infrastructure\Security\BrowserSetupToken;
use Mediarama\Security\Infrastructure\Authentication\DbalSecurityUserProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SetupController extends AbstractController
{
    private const CSRF_ID = 'mediarama_first_run_setup';

    public function __construct(
        private readonly FirstRunSetup $setup,
        private readonly BrowserSetupToken $browserToken,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly DbalSecurityUserProvider $users,
        private readonly Security $security,
    ) {
    }

    #[Route('/setup', name: 'app_setup', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $state = $this->setup->state();

        if (!$state->isPending()) {
            if ($request->isMethod('POST')) {
                return $this->renderSetup(
                    error: 'Setup has already been completed.',
                    status: Response::HTTP_CONFLICT,
                    setupAvailable: false,
                );
            }

            return $this->redirectToRoute(
                $this->getUser() !== null ? 'admin_dashboard' : 'app_login'
            );
        }

        if ($request->isMethod('POST')) {
            $csrf = new CsrfToken(
                self::CSRF_ID,
                (string) $request->request->get('_csrf_token', ''),
            );

            if (!$this->csrf->isTokenValid($csrf)) {
                return $this->renderSetup(
                    error: 'Setup authorization failed.',
                    status: Response::HTTP_FORBIDDEN,
                );
            }

            if (!$this->browserToken->isValid(
                (string) $request->request->get('setup_token', '')
            )) {
                return $this->renderSetup(
                    error: 'Setup authorization failed.',
                    status: Response::HTTP_FORBIDDEN,
                );
            }

            $profile = DeploymentProfile::tryFrom(
                (string) $request->request->get(
                    'deployment_profile',
                    DeploymentProfile::PrivateWorkspace->value,
                )
            );

            if ($profile === null) {
                return $this->renderSetup(
                    error: 'Choose a valid deployment profile.',
                    status: Response::HTTP_BAD_REQUEST,
                    request: $request,
                );
            }

            $password = (string) $request->request->get('password', '');
            $confirmation = (string) $request->request->get('password_confirmation', '');

            if (!hash_equals($password, $confirmation)) {
                return $this->renderSetup(
                    error: 'The password confirmation does not match.',
                    status: Response::HTTP_BAD_REQUEST,
                    request: $request,
                );
            }

            try {
                $result = $this->setup->complete(
                    (string) $request->request->get('username', ''),
                    $password,
                    $this->nullableString($request->request->get('email')),
                    $profile,
                    SetupCompletionMethod::Browser,
                );
            } catch (SetupValidationException $exception) {
                return $this->renderSetup(
                    error: $exception->getMessage(),
                    status: Response::HTTP_BAD_REQUEST,
                    request: $request,
                );
            } catch (SetupUnavailableException $exception) {
                return $this->renderSetup(
                    error: $exception->getMessage(),
                    status: Response::HTTP_CONFLICT,
                    request: $request,
                    setupAvailable: false,
                );
            }

            if (!$result->shouldAuthenticate) {
                return $this->redirectToRoute('app_login', ['setup' => 'completed']);
            }

            $securityUser = $this->users->loadUserByIdentifier($result->username);
            $this->security->login($securityUser);

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->renderSetup();
    }

    private function renderSetup(
        ?string $error = null,
        int $status = Response::HTTP_OK,
        ?Request $request = null,
        bool $setupAvailable = true,
    ): Response {
        $response = $this->render(
            'setup/index.html.twig',
            [
                'csrf_token' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
                'browser_setup_configured' => $this->browserToken->isConfigured(),
                'setup_available' => $setupAvailable,
                'error' => $error,
                'username' => $request !== null
                    ? (string) $request->request->get('username', '')
                    : '',
                'email' => $request !== null
                    ? (string) $request->request->get('email', '')
                    : '',
                'deployment_profile' => $request !== null
                    ? (string) $request->request->get(
                        'deployment_profile',
                        DeploymentProfile::PrivateWorkspace->value,
                    )
                    : DeploymentProfile::PrivateWorkspace->value,
            ],
            new Response(status: $status),
        );
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
