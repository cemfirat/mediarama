<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Organization\Application\OrganizationAiPreflightStore;
use Mediarama\Organization\Application\OrganizationProposalReview;
use Mediarama\Organization\Application\OrganizationProposalReviewQuery;
use Mediarama\Organization\Application\OrganizationProposalStore;
use Mediarama\Organization\Application\OrganizationProposalUnavailableException;
use Mediarama\Organization\Application\OrganizationRunUnavailableException;
use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalType;
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
    public function __construct(
        private readonly OrganizationProposalStore $store,
        private readonly OrganizationProposalReviewQuery $query,
        private readonly OrganizationProposalReview $review,
        private readonly OrganizationAiPreflightStore $preflights,
        private readonly CurrentUser $currentUser,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/library/organization', name: 'library_organization_runs', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->user();

        return $this->privateResponse($this->render(
            '@Mediarama/library/organization/index.html.twig',
            ['runs' => $this->store->runs($user->id)],
        ));
    }

    #[Route(
        '/library/organization/{id}',
        name: 'library_organization_run_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): Response
    {
        $user = $this->user();
        $runId = $this->id($id);

        try {
            $run = $this->store->run($user->id, $runId);
            $proposals = $this->query->proposals($user->id, $runId);
            $preflight = $this->preflights->forRun($user->id, $runId);
        } catch (OrganizationRunUnavailableException) {
            throw $this->createNotFoundException();
        }

        $tokens = [];
        foreach ($proposals as $proposal) {
            $proposalId = $proposal->id->toRfc4122();
            $tokens[$proposalId] = [
                'edit' => $this->csrf
                    ->getToken('organization_proposal_edit_'.$proposalId)
                    ->getValue(),
                'accept' => $this->csrf
                    ->getToken('organization_proposal_accept_'.$proposalId)
                    ->getValue(),
                'reject' => $this->csrf
                    ->getToken('organization_proposal_reject_'.$proposalId)
                    ->getValue(),
            ];
        }

        return $this->privateResponse($this->render(
            '@Mediarama/library/organization/show.html.twig',
            [
                'run' => $run,
                'proposals' => $proposals,
                'preflight' => $preflight,
                'tokens' => $tokens,
                'bulk_csrf_token' => $this->csrf
                    ->getToken('organization_run_bulk_'.$runId->toRfc4122())
                    ->getValue(),
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
        $this->requireCsrf(
            'organization_proposal_edit_'.$proposalId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $proposal = $this->store->proposal($user->id, $proposalId);
            $payload = $this->editedPayload($proposal->payload, $request);
            $this->review->edit($user->id, $proposalId, $payload);
            $this->addFlash('success', 'Proposal updated for review.');

            return $this->redirectToRoute(
                'library_organization_run_show',
                ['id' => $proposal->runId->toRfc4122()],
            );
        } catch (OrganizationProposalUnavailableException) {
            throw $this->createNotFoundException();
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectBackToProposalRun(
                $user->id,
                $proposalId,
            );
        }
    }

    #[Route(
        '/library/organization/proposals/{id}/accept',
        name: 'library_organization_proposal_accept',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function accept(string $id, Request $request): Response
    {
        return $this->singleAction(
            $id,
            $request,
            'accept',
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
        return $this->singleAction(
            $id,
            $request,
            'reject',
        );
    }

    #[Route(
        '/library/organization/{id}/bulk',
        name: 'library_organization_run_bulk',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function bulk(string $id, Request $request): Response
    {
        $user = $this->user();
        $runId = $this->id($id);
        $this->requireCsrf(
            'organization_run_bulk_'.$runId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $items = $this->query->proposals($user->id, $runId);
        } catch (OrganizationRunUnavailableException) {
            throw $this->createNotFoundException();
        }

        $allowed = [];
        foreach ($items as $item) {
            $allowed[$item->id->toRfc4122()] = true;
        }

        $ids = [];
        foreach ($request->request->all('proposal_ids') as $rawId) {
            try {
                $proposalId = Uuid::fromString((string) $rawId);
            } catch (\InvalidArgumentException) {
                $this->addFlash('danger', 'Selected proposal is invalid.');

                return $this->redirectToRoute(
                    'library_organization_run_show',
                    ['id' => $runId->toRfc4122()],
                );
            }

            if (!isset($allowed[$proposalId->toRfc4122()])) {
                $this->addFlash('danger', 'Selected proposal is unavailable.');

                return $this->redirectToRoute(
                    'library_organization_run_show',
                    ['id' => $runId->toRfc4122()],
                );
            }

            $ids[$proposalId->toRfc4122()] = $proposalId;
        }

        if ($ids === []) {
            $this->addFlash('warning', 'Select at least one proposal.');

            return $this->redirectToRoute(
                'library_organization_run_show',
                ['id' => $runId->toRfc4122()],
            );
        }

        $action = $request->request->getString('action');
        try {
            if ($action === 'accept') {
                $this->review->acceptMany(
                    $user->id,
                    array_values($ids),
                );
                $this->addFlash('success', 'Selected proposals were applied atomically.');
            } elseif ($action === 'reject') {
                $this->review->rejectMany(
                    $user->id,
                    array_values($ids),
                );
                $this->addFlash('success', 'Selected proposals were rejected.');
            } else {
                throw new \InvalidArgumentException(
                    'Choose accept or reject for the selected proposals.',
                );
            }
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute(
            'library_organization_run_show',
            ['id' => $runId->toRfc4122()],
        );
    }

    private function singleAction(
        string $id,
        Request $request,
        string $action,
    ): Response {
        $user = $this->user();
        $proposalId = $this->id($id);
        $this->requireCsrf(
            'organization_proposal_'.$action.'_'.$proposalId->toRfc4122(),
            (string) $request->request->get('_csrf_token', ''),
        );

        try {
            $proposal = $this->store->proposal($user->id, $proposalId);

            if ($action === 'accept') {
                $result = $this->review->accept(
                    $user->id,
                    $proposalId,
                );
                $this->addFlash(
                    'success',
                    $result->alreadyApplied
                        ? 'Proposal was already applied; no duplicate was created.'
                        : 'Proposal applied.',
                );
            } else {
                $this->review->rejectMany(
                    $user->id,
                    [$proposalId],
                );
                $this->addFlash('success', 'Proposal rejected.');
            }

            return $this->redirectToRoute(
                'library_organization_run_show',
                ['id' => $proposal->runId->toRfc4122()],
            );
        } catch (OrganizationProposalUnavailableException) {
            throw $this->createNotFoundException();
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectBackToProposalRun(
                $user->id,
                $proposalId,
            );
        }
    }

    private function redirectBackToProposalRun(
        Uuid $requesterId,
        Uuid $proposalId,
    ): Response {
        try {
            $proposal = $this->store->proposal(
                $requesterId,
                $proposalId,
            );
        } catch (OrganizationProposalUnavailableException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute(
            'library_organization_run_show',
            ['id' => $proposal->runId->toRfc4122()],
        );
    }

    private function editedPayload(
        OrganizationProposalPayload $current,
        Request $request,
    ): OrganizationProposalPayload {
        $payload = $current->payload();

        switch ($current->type) {
            case OrganizationProposalType::SmartCollection:
            case OrganizationProposalType::ManualCollection:
            case OrganizationProposalType::ReviewBucket:
                $payload['title'] = $request->request->getString('title');
                $payload['description'] = $this->nullableString(
                    $request->request->get('description'),
                );
                break;

            case OrganizationProposalType::Tag:
                $payload['name'] = $request->request->getString('name');
                break;

            case OrganizationProposalType::TitleDescription:
                $payload['title'] = $this->nullableString(
                    $request->request->get('title'),
                );
                $payload['description'] = $this->nullableString(
                    $request->request->get('description'),
                );
                break;

            case OrganizationProposalType::Cover:
                $payload['media_id'] = $this->id(
                    $request->request->getString('media_id'),
                )->toRfc4122();
                break;
        }

        return OrganizationProposalPayload::fromArray(
            $current->type,
            $payload,
        );
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

    private function id(string $value): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
