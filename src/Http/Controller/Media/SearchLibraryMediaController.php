<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Media;

use DateTimeImmutable;
use Mediarama\Media\Application\MediaSearch;
use Mediarama\Media\Application\MediaSearchCriteria;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SearchLibraryMediaController
{
    public function __construct(
        private MediaSearch $search,
        private CurrentUser $currentUser,
    ) {
    }

    #[Route('/api/library/media', name: 'library_media_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->query;
        $actor = $this->currentUser->requireUser()->id;

        $results = $this->search->search($actor, new MediaSearchCriteria(
            text: $q->getString('q') ?: null,
            creator: $q->getString('creator') ?: null,
            cameraMake: $q->getString('camera_make') ?: null,
            cameraModel: $q->getString('camera_model') ?: null,
            lens: $q->getString('lens') ?: null,
            minimumIso: $q->has('iso_min') ? $q->getInt('iso_min') : null,
            maximumIso: $q->has('iso_max') ? $q->getInt('iso_max') : null,
            capturedFrom: $q->getString('captured_from') !== '' ? new DateTimeImmutable($q->getString('captured_from')) : null,
            capturedUntil: $q->getString('captured_until') !== '' ? new DateTimeImmutable($q->getString('captured_until')) : null,
            hasLocation: $q->has('has_location') ? $q->getBoolean('has_location') : null,
            limit: min(max($q->getInt('limit', 50), 1), 200),
            offset: max($q->getInt('offset', 0), 0),
        ));

        return new JsonResponse([
            'items' => array_map(static fn ($item): array => [
                'id' => $item->id->toRfc4122(),
                'filename' => $item->originalFilename,
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
        ], 200, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
