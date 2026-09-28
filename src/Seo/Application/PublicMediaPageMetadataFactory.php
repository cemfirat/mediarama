<?php

declare(strict_types=1);

namespace Mediarama\Seo\Application;

use Mediarama\Collection\Application\PublicMediaDetailResult;
use Mediarama\Seo\Domain\PublicPageMetadata;
use Mediarama\Seo\Infrastructure\Http\PublicUrlGenerator;

final readonly class PublicMediaPageMetadataFactory
{
    private const SITE_NAME = 'Mediarama';

    public function __construct(private PublicUrlGenerator $urls)
    {
    }

    public function create(PublicMediaDetailResult $media): PublicPageMetadata
    {
        $canonical = $this->urls->route('public_media_show', [
            'id' => $media->id->toRfc4122(),
        ]);
        $socialTitle = $this->title($media);
        $description = $this->description($media);

        $imageUrl = null;
        $imageAlt = null;
        $profile = $media->preferredImageProfile();
        $version = $media->preferredImageVersion();

        if ($profile !== null && $version !== null) {
            $imageUrl = $this->urls->route('public_media_derivative', [
                'id' => $media->id->toRfc4122(),
                'version' => $version,
                'profile' => $profile,
            ]);
            $imageAlt = $media->title !== null && trim($media->title) !== ''
                ? trim($media->title)
                : 'Public image';
        }

        return new PublicPageMetadata(
            pageTitle: $socialTitle.' · '.self::SITE_NAME,
            socialTitle: $socialTitle,
            description: $description,
            canonicalUrl: $canonical,
            imageUrl: $imageUrl,
            imageAlt: $imageAlt,
            structuredDataJson: $media->indexable
                && $media->mediaType === 'image'
                && $imageUrl !== null
                    ? $this->imageStructuredData(
                        $media,
                        $canonical,
                        $socialTitle,
                        $description,
                        $imageUrl,
                    )
                    : null,
        );
    }

    private function title(PublicMediaDetailResult $media): string
    {
        $title = trim((string) $media->title);
        if ($title !== '') {
            return $title;
        }

        return match ($media->mediaType) {
            'image' => 'Image',
            'video' => 'Video',
            'audio' => 'Audio',
            default => 'Media',
        };
    }

    private function description(PublicMediaDetailResult $media): string
    {
        $description = trim((string) $media->description);
        if ($description !== '') {
            return preg_replace('/\s+/u', ' ', $description) ?? $description;
        }

        return match ($media->mediaType) {
            'image' => 'View this image on Mediarama.',
            'video' => 'View this video on Mediarama.',
            'audio' => 'View this audio item on Mediarama.',
            default => 'View this media item on Mediarama.',
        };
    }

    private function imageStructuredData(
        PublicMediaDetailResult $media,
        string $canonical,
        string $title,
        string $description,
        string $imageUrl,
    ): string {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            '@id' => $canonical.'#image',
            'contentUrl' => $imageUrl,
            'name' => $title,
            'caption' => $description,
            'mainEntityOfPage' => $canonical,
        ];

        if ($media->width !== null) {
            $data['width'] = $media->width;
        }

        if ($media->height !== null) {
            $data['height'] = $media->height;
        }

        return json_encode(
            $data,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );
    }
}
