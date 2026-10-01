<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Http\Support\InputBagValue;
use Mediarama\Organization\Application\OrganizationAiCoordinator;
use Mediarama\Organization\Application\OrganizationAiPreflightResult;
use Mediarama\Organization\Application\OrganizationAiPreflightStore;
use Mediarama\Organization\Application\OrganizationAiProviderExecutionException;
use Mediarama\Organization\Application\OrganizationAiProviderRegistry;
use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class OrganizationAiBrowserController extends AbstractController
{
    private const MAX_BROWSER_MEDIA = 200;

    public function __construct(
        private readonly OrganizationAiCoordinator $coordinator,
        private readonly OrganizationAiProviderRegistry $providers,
        private readonly OrganizationAiPreflightStore $preflights,
        private readonly CurrentUser $currentUser,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route(
        '/library/organization/ai/preflight',
        name: 'library_organization_ai_preflight_prepare',
        methods: ['POST'],
    )]
    public function prepare(Request $request): Response
    {
        $user = $this->user();
        $this->requireCsrf(
            'organization_ai_prepare',
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $mediaIds = $this->ids(
                $request->request->all('media_ids'),
            );
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute('library_home', [
                'organization_error' => 'invalid_scope',
            ]);
        }

        try {
            $providerKey = trim(
                $request->request->getString('provider_key'),
            );
            if ($providerKey === '') {
                throw new \InvalidArgumentException(
                    'Choose a configured AI provider.',
                );
            }

            $inputMode = OrganizationAiInputMode::tryFrom(
                $request->request->getString('input_mode'),
            );
            if ($inputMode === null) {
                throw new \InvalidArgumentException(
                    'Choose a valid AI input mode.',
                );
            }

            $preflight = $this->coordinator->prepare(
                $user->id,
                $providerKey,
                $this->capabilities(
                    $request->request->all('capabilities'),
                ),
                $inputMode,
                $mediaIds,
                InputBagValue::boolean($request->request, 'include_creator'),
                InputBagValue::boolean($request->request, 'include_location_name'),
            );
        } catch (\InvalidArgumentException|\DomainException) {
            return $this->renderSetup(
                $mediaIds,
                'The AI preflight could not be prepared. Check the provider capabilities and approved input scope.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->redirectToRoute(
            'library_organization_ai_preflight_show',
            ['id' => $preflight->id->toRfc4122()],
        );
    }

    #[Route(
        '/library/organization/ai/preflights/{id}',
        name: 'library_organization_ai_preflight_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id, Request $request): Response
    {
        $user = $this->user();
        $preflight = $this->preflight(
            $user->id,
            $this->id($id),
        );

        return $this->privateResponse($this->render(
            '@Mediarama/library/organization/ai_preflight.html.twig',
            [
                'preflight' => $preflight,
                'approval_error' => InputBagValue::booleanOrDefault($request->query, 'approval_error'),
                'execution_error' => InputBagValue::booleanOrDefault($request->query, 'execution_error'),
                'approved' => InputBagValue::booleanOrDefault($request->query, 'approved'),
            ],
        ));
    }

    #[Route(
        '/library/organization/ai/preflights/{id}/approve',
        name: 'library_organization_ai_preflight_approve',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function approve(string $id, Request $request): Response
    {
        $user = $this->user();
        $preflightId = $this->id($id);
        $this->preflight($user->id, $preflightId);
        $this->requireCsrf(
            'organization_ai_approve_'.$preflightId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $this->coordinator->approve(
                $user->id,
                $preflightId,
            );
        } catch (\DomainException) {
            return $this->redirectToRoute(
                'library_organization_ai_preflight_show',
                [
                    'id' => $preflightId->toRfc4122(),
                    'approval_error' => 1,
                ],
            );
        }

        return $this->redirectToRoute(
            'library_organization_ai_preflight_show',
            [
                'id' => $preflightId->toRfc4122(),
                'approved' => 1,
            ],
        );
    }

    #[Route(
        '/library/organization/ai/preflights/{id}/execute',
        name: 'library_organization_ai_preflight_execute',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function execute(string $id, Request $request): Response
    {
        $user = $this->user();
        $preflightId = $this->id($id);
        $this->preflight($user->id, $preflightId);
        $this->requireCsrf(
            'organization_ai_execute_'.$preflightId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $runId = $this->coordinator->execute(
                $user->id,
                $preflightId,
            );
        } catch (OrganizationAiProviderExecutionException|\DomainException) {
            return $this->redirectToRoute(
                'library_organization_ai_preflight_show',
                [
                    'id' => $preflightId->toRfc4122(),
                    'execution_error' => 1,
                ],
            );
        }

        return $this->redirectToRoute(
            'library_organization_run',
            [
                'id' => $runId->toRfc4122(),
                'created' => 1,
            ],
        );
    }

    /**
     * @param list<Uuid> $mediaIds
     */
    private function renderSetup(
        array $mediaIds,
        ?string $error,
        int $status = Response::HTTP_OK,
    ): Response {
        $providers = $this->providers->available();
        if ($providers === []) {
            return $this->redirectToRoute('library_home', [
                'organization_error' => 'no_ai_provider',
            ]);
        }

        return $this->privateResponse($this->render(
            '@Mediarama/library/organization/ai_setup.html.twig',
            [
                'providers' => $providers,
                'selected_media_ids' => $mediaIds,
                'prepare_csrf_token' => $this->csrf
                    ->getToken('organization_ai_prepare')
                    ->getValue(),
                'error' => $error,
            ],
            new Response(status: $status),
        ));
    }

    private function preflight(
        Uuid $requesterId,
        Uuid $preflightId,
    ): OrganizationAiPreflightResult {
        try {
            return $this->preflights->get(
                $requesterId,
                $preflightId,
            );
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @param list<mixed> $values
     * @return list<OrganizationAiCapability>
     */
    private function capabilities(array $values): array
    {
        if ($values === []) {
            throw new \InvalidArgumentException(
                'Choose at least one AI capability.',
            );
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    'AI capability selection is invalid.',
                );
            }

            $capability = OrganizationAiCapability::tryFrom(
                trim($value),
            );
            if ($capability === null) {
                throw new \InvalidArgumentException(
                    'AI capability selection is invalid.',
                );
            }

            $result[$capability->value] = $capability;
        }

        return array_values($result);
    }

    /**
     * @param list<mixed> $values
     * @return list<Uuid>
     */
    private function ids(array $values): array
    {
        if (
            $values === []
            || count($values) > self::MAX_BROWSER_MEDIA
        ) {
            throw new \InvalidArgumentException(
                'AI browser analysis requires 1-200 MediaAssets.',
            );
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    'AI browser MediaAsset selection is invalid.',
                );
            }

            try {
                $id = Uuid::fromString($value);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException(
                    'AI browser MediaAsset selection is invalid.',
                );
            }

            $ids[$id->toRfc4122()] = $id;
        }

        if (count($ids) !== count($values)) {
            throw new \InvalidArgumentException(
                'AI browser MediaAsset selection must be unique.',
            );
        }

        return array_values($ids);
    }

    private function id(string $value): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
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
