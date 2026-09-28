<?php

declare(strict_types=1);

namespace Mediarama\Seo\Application;

use Symfony\Component\Uid\Uuid;

final readonly class PublicMediaSeoText
{
    public function title(Uuid $id, ?string $title, string $mediaType): string
    {
        $title = trim((string) $title);
        if ($title !== '') {
            return $title;
        }

        return match ($mediaType) {
            'image' => 'Image',
            'video' => 'Video '.$id->toRfc4122(),
            'audio' => 'Audio',
            default => 'Media',
        };
    }

    public function description(?string $description, string $mediaType): string
    {
        $description = trim((string) $description);
        if ($description !== '') {
            return preg_replace('/\s+/u', ' ', $description) ?? $description;
        }

        return match ($mediaType) {
            'image' => 'View this image on Mediarama.',
            'video' => 'View this video on Mediarama.',
            'audio' => 'View this audio item on Mediarama.',
            default => 'View this media item on Mediarama.',
        };
    }
}
