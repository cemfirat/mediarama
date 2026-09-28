<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Media;

use Mediarama\Http\Support\LibraryMediaSearchCriteriaFactory;
use Mediarama\Media\Application\LibraryMediaSearch;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LibrarySearchMediaController
{
    public function __construct(
        private LibraryMediaSearch $search,
        private LibraryMediaSearchCriteriaFactory $criteriaFactory,
        private CurrentUser $currentUser,
    ) {
    }

    #[Route('/api/library/media', name: 'library_media_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $criteria = $this->criteriaFactory->fromInput($request->query);
        } catch (\Throwable) {
            return new JsonResponse(
                ['error' => 'invalid_search_query'],
                Response::HTTP_BAD_REQUEST,
                $this->responseHeaders(),
            );
        }

        $results = $this->search->search(
            $this->currentUser->requireUser()->id,
            $criteria,
        );

        return new JsonResponse([
            'items' => array_map(static fn ($item): array => [
                'id' => $item->id->toRfc4122(),
                'original_filename' => $item->originalFilename,
                'mime_type' => $item->mimeType,
                'title' => $item->title,
                'description' => $item->description,
                'captured_at' => $item->capturedAt?->format(DATE_ATOM),
                'creator' => $item->creator,
                'camera_make' => $item->cameraMake,
                'camera_model' => $item->cameraModel,
                'lens' => $item->lens,
                'iso' => $item->iso,
                'location_name' => $item->locationName,
            ], $results),
        ], Response::HTTP_OK, $this->responseHeaders());
    }

    /** @return array<string, string> */
    private function responseHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
