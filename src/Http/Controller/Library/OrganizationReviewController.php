<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Http\Support\InputBagValue;
use Mediarama\Organization\Application\DeterministicOrganizationAnalyzer;
use Mediarama\Organization\Application\OrganizationAiPreflightStore;
use Mediarama\Organization\Application\OrganizationAiProviderRegistry;
use Mediarama\Organization\Application\OrganizationMetadataSnapshotQuery;
use Mediarama\Organization\Application\OrganizationProposalApplication;
use Mediarama\Organization\Application\OrganizationProposalPayloadEditor;
use Mediarama\Organization\Application\OrganizationProposalStaleException;
use Mediarama\Organization\Application\OrganizationProposalStore;
use Mediarama\Organization\Application\OrganizationProposalUnavailableException;
use Mediarama\Organization\Application\OrganizationRunUnavailableException;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class OrganizationReviewController extends AbstractController
{
    private const MAX_BROWSER_ANALYSIS_MEDIA = 200;
    private const PREVIEW_MEDIA = 6;

    public function __construct(
        private readonly OrganizationProposalStore $store,
        private readonly DeterministicOrganizationAnalyzer $analyzer,
        private readonly OrganizationProposalApplication $application,
        private readonly OrganizationProposalPayloadEditor $editor,
        private readonly OrganizationMetadataSnapshotQuery $metadata,
        private readonly OrganizationAiPreflightStore $preflights,
        private readonly OrganizationAiProviderRegistry $aiProviders,
        private readonly CurrentUser $currentUser,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/library/organization', name: 'library_organization', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->user();

        return $this->privateResponse($this->render(
            '@Mediarama/library/organization/index.html.twig',
            ['runs' => $this->store->runs($user->id)],
        ));
    }

    #[Route('/library/organization/analyze', name: 'library_organization_analyze', methods: ['POST'])]
    public function analyze(Request $request): Response
    {
        $user = $this->user();
        $this->requireCsrf(
            'organization_analyze',
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $mediaIds = $this->ids(
                $request->request->all('media_ids'),
                self::MAX_BROWSER_ANALYSIS_MEDIA,
            );
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute('library_home', [
                'organization_error' => 'invalid_scope',
            ]);
        }

        if ($request->request->getString('analysis_mode') === 'ai') {
            $providers = $this->aiProviders->available();
            if ($providers === []) {
                return $this->redirectToRoute('library_home', [
                    'organization_error' => 'no_ai_provider',
                ]);
            }

            try {
                // Validate the complete requester scope before exposing provider
                // choices. The setup page itself contains no media metadata.
                $this->metadata->snapshot($user->id, $mediaIds);
            } catch (\Throwable) {
                return $this->redirectToRoute('library_home', [
                    'organization_error' => 'invalid_scope',
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
                    'error' => null,
                ],
            ));
        }

        try {
            $runId = $this->analyzer->analyze(
                $user->id,
                $mediaIds,
            );
        } catch (\InvalidArgumentException|\DomainException) {
            return $this->redirectToRoute('library_home', [
                'organization_error' => 'invalid_scope',
            ]);
        }

        return $this->redirectToRoute(
            'library_organization_run',
            ['id' => $runId->toRfc4122(), 'created' => 1],
        );
    }

    #[Route(
        '/library/organization/runs/{id}',
        name: 'library_organization_run',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id, Request $request): Response
    {
        $user = $this->user();
        $runId = $this->id($id);

        try {
            $run = $this->store->run($user->id, $runId);
            $proposals = $this->store->proposals($user->id, $runId);
        } catch (OrganizationRunUnavailableException) {
            throw $this->createNotFoundException();
        }

        $previews = [];
        foreach ($proposals as $proposal) {
            $previewIds = array_slice(
                $proposal->affectedMediaIds,
                0,
                self::PREVIEW_MEDIA,
            );

            try {
                $previews[$proposal->id->toRfc4122()] = $previewIds === []
                    ? []
                    : $this->metadata->snapshot($user->id, $previewIds);
            } catch (\Throwable) {
                // A stale proposal must remain reviewable so the user can see
                // and reject it; application will revalidate and fail closed.
                $previews[$proposal->id->toRfc4122()] = [];
            }
        }

        return $this->privateResponse($this->render(
            '@Mediarama/library/organization/run.html.twig',
            [
                'run' => $run,
                'proposals' => $proposals,
                'previews' => $previews,
                'preflight' => $this->preflights->forRun($user->id, $runId),
                'created' => InputBagValue::booleanOrDefault($request->query, 'created'),
                'edited' => InputBagValue::booleanOrDefault($request->query, 'edited'),
                'applied' => InputBagValue::booleanOrDefault($request->query, 'applied'),
                'already_applied' => InputBagValue::booleanOrDefault($request->query, 'already'),
                'rejected' => InputBagValue::booleanOrDefault($request->query, 'rejected'),
                'stale' => InputBagValue::booleanOrDefault($request->query, 'stale'),
                'edit_error' => InputBagValue::booleanOrDefault($request->query, 'edit_error'),
                'apply_error' => InputBagValue::booleanOrDefault($request->query, 'apply_error'),
                'bulk_applied' => InputBagValue::integerOrDefault($request->query, 'bulk_applied'),
                'bulk_rejected' => InputBagValue::integerOrDefault($request->query, 'bulk_rejected'),
                'bulk_invalidated' => InputBagValue::integerOrDefault($request->query, 'bulk_invalidated'),
                'bulk_failed' => InputBagValue::integerOrDefault($request->query, 'bulk_failed'),
                'bulk_error' => $request->query->getString('bulk_error'),
            ],
        ));
    }

    #[Route(
        '/library/organization/proposals/{id}/edit',
        name: 'library_organization_proposal_edit',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function edit(string $id, Request $request): Response
    {
        $user = $this->user();
        $proposalId = $this->id($id);
        $proposal = $this->proposal($user->id, $proposalId);

        $this->requireCsrf(
            'organization_proposal_edit_'.$proposalId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $payload = $this->editor->edit(
                $proposal->payload,
                $request->request->all('changes'),
            );
            $this->store->updatePayload(
                $user->id,
                $proposalId,
                $payload,
            );
        } catch (\InvalidArgumentException|\DomainException) {
            return $this->redirectToRoute(
                'library_organization_run',
                [
                    'id' => $proposal->runId->toRfc4122(),
                    'edit_error' => 1,
                ],
            );
        }

        return $this->redirectToRoute(
            'library_organization_run',
            ['id' => $proposal->runId->toRfc4122(), 'edited' => 1],
        );
    }

    #[Route(
        '/library/organization/proposals/{id}/apply',
        name: 'library_organization_proposal_apply',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function apply(string $id, Request $request): Response
    {
        $user = $this->user();
        $proposalId = $this->id($id);
        $proposal = $this->proposal($user->id, $proposalId);

        $this->requireCsrf(
            'organization_proposal_apply_'.$proposalId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $result = $this->application->apply(
                $user->id,
                $proposalId,
            );
        } catch (OrganizationProposalStaleException) {
            return $this->redirectToRoute(
                'library_organization_run',
                ['id' => $proposal->runId->toRfc4122(), 'stale' => 1],
            );
        } catch (\DomainException|\InvalidArgumentException) {
            return $this->redirectToRoute(
                'library_organization_run',
                ['id' => $proposal->runId->toRfc4122(), 'apply_error' => 1],
            );
        }

        return $this->redirectToRoute(
            'library_organization_run',
            [
                'id' => $proposal->runId->toRfc4122(),
                'applied' => 1,
                'already' => $result->alreadyApplied ? 1 : 0,
            ],
        );
    }

    #[Route(
        '/library/organization/proposals/{id}/reject',
        name: 'library_organization_proposal_reject',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function reject(string $id, Request $request): Response
    {
        $user = $this->user();
        $proposalId = $this->id($id);
        $proposal = $this->proposal($user->id, $proposalId);

        $this->requireCsrf(
            'organization_proposal_reject_'.$proposalId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $this->store->reject($user->id, $proposalId);
        } catch (\DomainException) {
            return $this->redirectToRoute(
                'library_organization_run',
                ['id' => $proposal->runId->toRfc4122(), 'apply_error' => 1],
            );
        }

        return $this->redirectToRoute(
            'library_organization_run',
            ['id' => $proposal->runId->toRfc4122(), 'rejected' => 1],
        );
    }

    #[Route(
        '/library/organization/runs/{id}/bulk-review',
        name: 'library_organization_bulk_review',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function bulk(string $id, Request $request): Response
    {
        $user = $this->user();
        $runId = $this->id($id);

        try {
            $this->store->run($user->id, $runId);
        } catch (OrganizationRunUnavailableException) {
            throw $this->createNotFoundException();
        }

        $this->requireCsrf(
            'organization_bulk_'.$runId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        $action = $request->request->getString('action');
        if (!in_array($action, ['apply', 'reject'], true)) {
            return $this->redirectToRoute(
                'library_organization_run',
                ['id' => $runId->toRfc4122(), 'bulk_error' => 'action'],
            );
        }

        try {
            $proposalIds = $this->ids(
                $request->request->all('proposal_ids'),
                200,
            );
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute(
                'library_organization_run',
                ['id' => $runId->toRfc4122(), 'bulk_error' => 'selection'],
            );
        }

        $selected = [];
        foreach ($proposalIds as $proposalId) {
            $proposal = $this->proposal($user->id, $proposalId);
            if (!$proposal->runId->equals($runId)) {
                throw $this->createNotFoundException();
            }
            $selected[] = $proposal;
        }

        $applied = 0;
        $rejected = 0;
        $invalidated = 0;
        $failed = 0;

        foreach ($selected as $proposal) {
            try {
                if ($action === 'apply') {
                    $this->application->apply(
                        $user->id,
                        $proposal->id,
                    );
                    ++$applied;
                } else {
                    $this->store->reject(
                        $user->id,
                        $proposal->id,
                    );
                    ++$rejected;
                }
            } catch (OrganizationProposalStaleException) {
                ++$invalidated;
            } catch (\DomainException|\InvalidArgumentException) {
                ++$failed;
            }
        }

        return $this->redirectToRoute(
            'library_organization_run',
            [
                'id' => $runId->toRfc4122(),
                'bulk_applied' => $applied,
                'bulk_rejected' => $rejected,
                'bulk_invalidated' => $invalidated,
                'bulk_failed' => $failed,
            ],
        );
    }

    private function proposal(
        Uuid $requesterId,
        Uuid $proposalId,
    ): \Mediarama\Organization\Application\OrganizationProposalResult {
        try {
            return $this->store->proposal(
                $requesterId,
                $proposalId,
            );
        } catch (OrganizationProposalUnavailableException) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @param list<mixed> $values
     * @return list<Uuid>
     */
    private function ids(array $values, int $maximum): array
    {
        if ($values === [] || count($values) > $maximum) {
            throw new \InvalidArgumentException('Invalid organization selection.');
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid organization selection.');
            }

            $id = $this->id($value);
            $ids[$id->toRfc4122()] = $id;
        }

        if (count($ids) !== count($values)) {
            throw new \InvalidArgumentException(
                'Organization selection contains duplicates.',
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
