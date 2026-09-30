<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Media;

use Mediarama\Http\Support\InputBagValue;
use Mediarama\Media\Application\PublicMediaSearch;
use Mediarama\Media\Application\PublicMediaSearchCriteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

        try {
            $criteria = new PublicMediaSearchCriteria(
                text: $q->getString('q') ?: null,
                limit: min(max(InputBagValue::integer($q, 'limit', 50), 1), 200),
                offset: max(InputBagValue::integer($q, 'offset', 0), 0),
            );
        } catch (\InvalidArgumentException) {
            return new JsonResponse(
                ['error' => 'invalid_search_query'],
                Response::HTTP_BAD_REQUEST,
                ['X-Robots-Tag' => 'noindex, nofollow'],
            );
        }

        $results = $this->search->search($criteria);

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
