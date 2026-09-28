<?php

declare(strict_types=1);

namespace Mediarama\Seo\Application;

use Mediarama\Collection\Application\PublicCollectionResult;
use Mediarama\Seo\Domain\PublicPageMetadata;
use Mediarama\Seo\Infrastructure\Http\PublicUrlGenerator;

final readonly class PublicCollectionPageMetadataFactory
{
    private const SITE_NAME = 'Mediarama';

    public function __construct(private PublicUrlGenerator $urls)
    {
    }

    public function index(bool $indexable): PublicPageMetadata
    {
        $canonical = $this->urls->route('public_collections');
        $description = 'Browse public photo and video collections on Mediarama.';

        return new PublicPageMetadata(
            pageTitle: 'Collections · '.self::SITE_NAME,
            socialTitle: 'Collections',
            description: $description,
            canonicalUrl: $canonical,
            imageUrl: null,
            imageAlt: null,
            structuredDataJson: $indexable
                ? $this->encode([
                    '@context' => 'https://schema.org',
                    '@graph' => [[
                        '@type' => 'CollectionPage',
                        '@id' => $canonical.'#webpage',
                        'url' => $canonical,
                        'name' => 'Collections',
                        'description' => $description,
                    ]],
                ])
                : null,
        );
    }

    public function collection(
        PublicCollectionResult $collection,
        bool $coverIndexable,
    ): PublicPageMetadata {
        $canonical = $this->urls->route('public_collection_show', [
            'id' => $collection->id->toRfc4122(),
        ]);
        $description = $this->description(
            $collection->description,
            sprintf('Browse photos and videos in %s.', $collection->title),
        );

        $imageUrl = null;
        $imageAlt = null;
        if (
            $collection->coverMediaId !== null
            && $collection->coverThumbnailVersion !== null
        ) {
            $imageUrl = $this->urls->route('public_media_derivative', [
                'id' => $collection->coverMediaId->toRfc4122(),
                'version' => $collection->coverThumbnailVersion,
                'profile' => 'thumbnail',
            ]);
            $imageAlt = sprintf('Cover image for %s', $collection->title);
        }

        return new PublicPageMetadata(
            pageTitle: $collection->title.' · '.self::SITE_NAME,
            socialTitle: $collection->title,
            description: $description,
            canonicalUrl: $canonical,
            imageUrl: $imageUrl,
            imageAlt: $imageAlt,
            structuredDataJson: $collection->indexable
                ? $this->collectionStructuredData(
                    $collection,
                    $canonical,
                    $description,
                    $imageUrl,
                    $imageAlt,
                    $coverIndexable,
                )
                : null,
        );
    }

    private function collectionStructuredData(
        PublicCollectionResult $collection,
        string $canonical,
        string $description,
        ?string $imageUrl,
        ?string $imageAlt,
        bool $coverIndexable,
    ): string {
        $breadcrumbId = $canonical.'#breadcrumb';
        $collectionNode = [
            '@type' => 'CollectionPage',
            '@id' => $canonical.'#webpage',
            'url' => $canonical,
            'name' => $collection->title,
            'description' => $description,
            'breadcrumb' => ['@id' => $breadcrumbId],
        ];

        $graph = [];
        if ($coverIndexable && $imageUrl !== null && $imageAlt !== null) {
            $imageId = $canonical.'#primaryimage';
            $collectionNode['primaryImageOfPage'] = ['@id' => $imageId];
            $graph[] = [
                '@type' => 'ImageObject',
                '@id' => $imageId,
                'contentUrl' => $imageUrl,
                'name' => $imageAlt,
            ];
        }

        array_unshift(
            $graph,
            $collectionNode,
            [
                '@type' => 'BreadcrumbList',
                '@id' => $breadcrumbId,
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => 'Collections',
                        'item' => $this->urls->route('public_collections'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => $collection->title,
                        'item' => $canonical,
                    ],
                ],
            ],
        );

        return $this->encode([
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ]);
    }

    private function description(?string $value, string $fallback): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );
    }
}
