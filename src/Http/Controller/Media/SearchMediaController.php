<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Media;

use Mediarama\Media\Application\PublicMediaSearch;
use Mediarama\Media\Application\PublicMediaSearchCriteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SearchMediaController
{
    public function __construct(private PublicMediaSearch $search)
    {
    }

    #[Route('/api/media', name: 'media_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->query;

        $results = $this->search->search(new PublicMediaSearchCriteria(
            text: $q->getString('q') ?: null,
            limit: min(max($q->getInt('limit', 50), 1), 200),
            offset: max($q->getInt('offset', 0), 0),
        ));

        return new JsonResponse([
            'items' => array_map(static fn ($item): array => [
                'id' => $item->id->toRfc4122(),
                'mime_type' => $item->mimeType,
                'media_type' => $item->mediaType,
                'title' => $item->title,
                'description' => $item->description,
                'thumbnail_version' => $item->thumbnailVersion,
                'preview_version' => $item->previewVersion,
            ], $results),
        ], 200, [
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
