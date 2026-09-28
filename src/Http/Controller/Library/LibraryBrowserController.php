<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Library;

use Mediarama\Http\Support\LibraryMediaSearchCriteriaFactory;
use Mediarama\Media\Application\LibraryMediaSearch;
use Mediarama\Media\Application\LibraryMediaSearchCriteria;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class LibraryBrowserController extends AbstractController
{
    public function __construct(
        private readonly LibraryMediaSearch $search,
        private readonly LibraryMediaSearchCriteriaFactory $criteriaFactory,
        private readonly CurrentUser $currentUser,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/library', name: 'library_home', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        try {
            $user = $this->currentUser->requireUser();
        } catch (\DomainException) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        $error = null;
        try {
            $criteria = $this->criteriaFactory->fromInput($request->query);
            $results = $this->search->search($user->id, $criteria);
        } catch (\InvalidArgumentException) {
            $criteria = new LibraryMediaSearchCriteria();
            $results = [];
            $error = 'The library filter is invalid.';
        }

        $response = $this->render(
            '@Mediarama/library/index.html.twig',
            [
                'items' => $results,
                'filters' => $this->filters($request),
                'filter_save_supported' => $this->filterSaveSupported($criteria),
                'filter_has_compatible_rule' => $this->filterHasCompatibleRule($criteria),
                'filter_csrf_token' => $this->csrf
                    ->getToken('smart_collection_from_filter')
                    ->getValue(),
                'organization_csrf_token' => $this->csrf
                    ->getToken('organization_analyze')
                    ->getValue(),
                'error' => $error,
                'smart_error' => $request->query->getString('smart_error') ?: null,
                'organization_error' => $request->query->getString('organization_error') ?: null,
            ],
            new Response(status: $error === null ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST),
        );

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** @return array<string,string> */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach ([
            'q',
            'creator',
            'camera_make',
            'camera_model',
            'lens',
            'media_type',
            'location_name',
            'tag',
            'rating_min',
            'orientation',
            'iso_min',
            'iso_max',
            'captured_from',
            'captured_until',
            'has_location',
        ] as $key) {
            $filters[$key] = $request->query->getString($key);
        }

        return $filters;
    }

    private function filterSaveSupported(LibraryMediaSearchCriteria $criteria): bool
    {
        return $criteria->text === null
            && $criteria->minimumIso === null
            && $criteria->maximumIso === null
            && $criteria->hasLocation === null;
    }

    private function filterHasCompatibleRule(LibraryMediaSearchCriteria $criteria): bool
    {
        return trim((string) $criteria->creator) !== ''
            || trim((string) $criteria->cameraMake) !== ''
            || trim((string) $criteria->cameraModel) !== ''
            || trim((string) $criteria->lens) !== ''
            || $criteria->mediaType !== null
            || trim((string) $criteria->locationName) !== ''
            || trim((string) $criteria->tag) !== ''
            || $criteria->minimumRating !== null
            || $criteria->orientation !== null
            || $criteria->capturedFrom !== null
            || $criteria->capturedUntil !== null;
    }
}
