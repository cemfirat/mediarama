<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Seo\Application\PublicSitemapQuery;
use Mediarama\Seo\Infrastructure\Http\PublicUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicSitemapController extends AbstractController
{
    private const COLLECTIONS_PER_SITEMAP = 250;

    public function __construct(
        private readonly PublicSitemapQuery $sitemap,
        private readonly PlatformSettingsRepository $settings,
        private readonly PublicUrlGenerator $urls,
    ) {
    }

    #[Route('/sitemap.xml', name: 'public_sitemap_index', methods: ['GET'])]
    public function index(): Response
    {
        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            throw $this->createNotFoundException();
        }

        $collectionCount = $this->sitemap->indexableCollectionCount();
        $pageCount = max(
            1,
            (int) ceil($collectionCount / self::COLLECTIONS_PER_SITEMAP),
        );

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        for ($page = 1; $page <= $pageCount; ++$page) {
            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>'.$this->xml($this->urls->route(
                'public_sitemap_collections',
                ['page' => $page],
            )).'</loc>';
            $lines[] = '  </sitemap>';
        }

        $lines[] = '</sitemapindex>';

        return $this->xmlResponse(implode("\n", $lines)."\n");
    }

    #[Route(
        '/sitemaps/collections-{page}.xml',
        name: 'public_sitemap_collections',
        requirements: ['page' => '[1-9]\d*'],
        methods: ['GET'],
    )]
    public function collections(int $page): Response
    {
        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            throw $this->createNotFoundException();
        }

        $collectionCount = $this->sitemap->indexableCollectionCount();
        $pageCount = max(
            1,
            (int) ceil($collectionCount / self::COLLECTIONS_PER_SITEMAP),
        );

        if ($page > $pageCount) {
            throw $this->createNotFoundException();
        }

        $collections = $this->sitemap->indexableCollections(
            self::COLLECTIONS_PER_SITEMAP,
            ($page - 1) * self::COLLECTIONS_PER_SITEMAP,
        );

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">',
        ];

        if (
            $page === 1
            && $settings->searchIndexDefault === SearchIndexPolicy::Index
        ) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.$this->xml(
                $this->urls->route('public_collections')
            ).'</loc>';
            $lines[] = '  </url>';
        }

        foreach ($collections as $collection) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.$this->xml($this->urls->route(
                'public_collection_show',
                ['id' => $collection->id->toRfc4122()],
            )).'</loc>';

            foreach ($collection->images as $image) {
                $lines[] = '    <image:image>';
                $lines[] = '      <image:loc>'.$this->xml($this->urls->route(
                    'public_media_derivative',
                    [
                        'id' => $image->mediaId->toRfc4122(),
                        'version' => $image->processingVersion,
                        'profile' => $image->profile,
                    ],
                )).'</image:loc>';
                $lines[] = '    </image:image>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return $this->xmlResponse(implode("\n", $lines)."\n");
    }

    private function xmlResponse(string $xml): Response
    {
        return new Response(
            $xml,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function xml(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
