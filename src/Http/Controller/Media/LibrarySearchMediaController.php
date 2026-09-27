<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Media;

use DateTimeImmutable;
use Mediarama\Media\Application\LibraryMediaSearch;
use Mediarama\Media\Application\LibraryMediaSearchCriteria;
use Mediarama\Security\Application\CurrentUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LibrarySearchMediaController
{
    public function __construct(
        private LibraryMediaSearch $search,
        private CurrentUser $currentUser,
    ) {
    }

    #[Route('/api/library/media', name: 'library_media_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $criteria = $this->criteria($request);
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

    private function criteria(Request $request): LibraryMediaSearchCriteria
    {
        $q = $request->query;

        return new LibraryMediaSearchCriteria(
            text: $q->getString('q') ?: null,
            creator: $q->getString('creator') ?: null,
            cameraMake: $q->getString('camera_make') ?: null,
            cameraModel: $q->getString('camera_model') ?: null,
            lens: $q->getString('lens') ?: null,
            minimumIso: $this->positiveInteger($request, 'iso_min'),
            maximumIso: $this->positiveInteger($request, 'iso_max'),
            capturedFrom: $this->date($request, 'captured_from'),
            capturedUntil: $this->date($request, 'captured_until'),
            hasLocation: $this->boolean($request, 'has_location'),
            limit: min(max($q->getInt('limit', 50), 1), 200),
            offset: max($q->getInt('offset', 0), 0),
        );
    }

    private function positiveInteger(Request $request, string $key): ?int
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $value = trim($request->query->getString($key));
        if ($value === '' || !ctype_digit($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException('Invalid positive integer.');
        }

        return (int) $value;
    }

    private function boolean(Request $request, string $key): ?bool
    {
        if (!$request->query->has($key)) {
            return null;
        }

        return match (strtolower(trim($request->query->getString($key)))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new \InvalidArgumentException('Invalid boolean.'),
        };
    }

    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $value = trim($request->query->getString($key));
        if ($value === '') {
            throw new \InvalidArgumentException('Invalid date.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        } else {
            $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        return $date;
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
