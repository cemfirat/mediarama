<?php

declare(strict_types=1);

namespace Mediarama\Seo\Application;

use Mediarama\Collection\Application\PublicMediaDetailResult;
use Mediarama\Seo\Domain\PublicPageMetadata;
use Mediarama\Seo\Infrastructure\Http\PublicUrlGenerator;

final readonly class PublicMediaPageMetadataFactory
{
    private const SITE_NAME = 'Mediarama';

    public function __construct(
        private PublicUrlGenerator $urls,
        private PublicMediaSeoText $text,
    ) {
    }

    public function create(PublicMediaDetailResult $media): PublicPageMetadata
    {
        $canonical = $this->urls->route('public_media_show', [
            'id' => $media->id->toRfc4122(),
        ]);
        $socialTitle = $this->text->title(
            $media->id,
            $media->title,
            $media->mediaType,
        );
        $description = $this->text->description(
            $media->description,
            $media->mediaType,
        );

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
            $imageAlt = $socialTitle;
        } elseif ($media->hasVideoPresentation()) {
            $imageUrl = $this->urls->route('public_video_derivative', [
                'id' => $media->id->toRfc4122(),
                'version' => $media->videoPresentationVersion,
                'profile' => 'poster',
            ]);
            $imageAlt = $socialTitle;
        }

        $structuredDataJson = null;
        if ($media->indexable) {
            if ($media->mediaType === 'image' && $imageUrl !== null) {
                $structuredDataJson = $this->imageStructuredData(
                    $media,
                    $canonical,
                    $socialTitle,
                    $description,
                    $imageUrl,
                );
            } elseif (
                $media->mediaType === 'video'
                && $media->hasVideoPresentation()
                && $imageUrl !== null
                && $media->publishedAt !== null
            ) {
                $structuredDataJson = $this->videoStructuredData(
                    $media,
                    $canonical,
                    $socialTitle,
                    $description,
                    $imageUrl,
                );
            }
        }

        return new PublicPageMetadata(
            pageTitle: $socialTitle.' · '.self::SITE_NAME,
            socialTitle: $socialTitle,
            description: $description,
            canonicalUrl: $canonical,
            imageUrl: $imageUrl,
            imageAlt: $imageAlt,
            structuredDataJson: $structuredDataJson,
        );
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

        return $this->json($data);
    }

    private function videoStructuredData(
        PublicMediaDetailResult $media,
        string $canonical,
        string $title,
        string $description,
        string $posterUrl,
    ): string {
        if (
            $media->videoPresentationVersion === null
            || $media->publishedAt === null
        ) {
            throw new \LogicException(
                'Video structured data requires a complete public presentation and publication time.',
            );
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            '@id' => $canonical.'#video',
            'name' => $title,
            'description' => $description,
            'thumbnailUrl' => $posterUrl,
            'uploadDate' => $media->publishedAt->format(DATE_ATOM),
            'contentUrl' => $this->urls->route('public_video_derivative', [
                'id' => $media->id->toRfc4122(),
                'version' => $media->videoPresentationVersion,
                'profile' => 'browser_mp4',
            ]),
            'mainEntityOfPage' => $canonical,
        ];

        $duration = $this->isoDuration($media->durationMs);
        if ($duration !== null) {
            $data['duration'] = $duration;
        }

        return $this->json($data);
    }

    private function isoDuration(?int $durationMs): ?string
    {
        if ($durationMs === null || $durationMs <= 0) {
            return null;
        }

        $wholeSeconds = intdiv($durationMs, 1000);
        $milliseconds = $durationMs % 1000;
        $hours = intdiv($wholeSeconds, 3600);
        $minutes = intdiv($wholeSeconds % 3600, 60);
        $seconds = $wholeSeconds % 60;

        $secondsValue = (string) $seconds;
        if ($milliseconds > 0) {
            $secondsValue .= '.'.rtrim(
                str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT),
                '0',
            );
        }

        $duration = 'PT';
        if ($hours > 0) {
            $duration .= $hours.'H';
        }
        if ($minutes > 0) {
            $duration .= $minutes.'M';
        }

        return $duration.$secondsValue.'S';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data): string
    {
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
