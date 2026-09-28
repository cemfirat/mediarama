<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Seo\Application\PublicMediaSeoText;
use Mediarama\Seo\Application\PublicSitemapQuery;
use Mediarama\Seo\Infrastructure\Http\PublicUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicSitemapController extends AbstractController
{
    private const COLLECTIONS_PER_SITEMAP = 250;
    private const MEDIA_PER_SITEMAP = 1000;

    public function __construct(
        private readonly PublicSitemapQuery $sitemap,
        private readonly PlatformSettingsRepository $settings,
        private readonly PublicUrlGenerator $urls,
        private readonly PublicMediaSeoText $mediaText,
    ) {
    }

    #[Route('/sitemap.xml', name: 'public_sitemap_index', methods: ['GET'])]
    public function index(): Response
    {
        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            throw $this->createNotFoundException();
        }

        $collectionPageCount = max(
            1,
            (int) ceil(
                $this->sitemap->indexableCollectionCount()
                / self::COLLECTIONS_PER_SITEMAP
            ),
        );
        $mediaPageCount = (int) ceil(
            $this->sitemap->indexableMediaCount() / self::MEDIA_PER_SITEMAP,
        );

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        for ($page = 1; $page <= $collectionPageCount; ++$page) {
            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>'.$this->xml($this->urls->route(
                'public_sitemap_collections',
                ['page' => $page],
            )).'</loc>';
            $lines[] = '  </sitemap>';
        }

        for ($page = 1; $page <= $mediaPageCount; ++$page) {
            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>'.$this->xml($this->urls->route(
                'public_sitemap_media',
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

        $pageCount = max(
            1,
            (int) ceil(
                $this->sitemap->indexableCollectionCount()
                / self::COLLECTIONS_PER_SITEMAP
            ),
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

    #[Route(
        '/sitemaps/media-{page}.xml',
        name: 'public_sitemap_media',
        requirements: ['page' => '[1-9]\d*'],
        methods: ['GET'],
    )]
    public function media(int $page): Response
    {
        if (!$this->settings->current()->publicPublishingEnabled) {
            throw $this->createNotFoundException();
        }

        $mediaCount = $this->sitemap->indexableMediaCount();
        $pageCount = (int) ceil($mediaCount / self::MEDIA_PER_SITEMAP);

        if ($pageCount < 1 || $page > $pageCount) {
            throw $this->createNotFoundException();
        }

        $media = $this->sitemap->indexableMedia(
            self::MEDIA_PER_SITEMAP,
            ($page - 1) * self::MEDIA_PER_SITEMAP,
        );

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">',
        ];

        foreach ($media as $item) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.$this->xml($this->urls->route(
                'public_media_show',
                ['id' => $item->id->toRfc4122()],
            )).'</loc>';

            if ($item->video !== null) {
                $title = $this->mediaText->title(
                    $item->id,
                    $item->video->title,
                    'video',
                );
                $description = $this->mediaText->description(
                    $item->video->description,
                    'video',
                );
                $posterUrl = $this->urls->route(
                    'public_video_derivative',
                    [
                        'id' => $item->id->toRfc4122(),
                        'version' => $item->video->processingVersion,
                        'profile' => 'poster',
                    ],
                );
                $contentUrl = $this->urls->route(
                    'public_video_derivative',
                    [
                        'id' => $item->id->toRfc4122(),
                        'version' => $item->video->processingVersion,
                        'profile' => 'browser_mp4',
                    ],
                );

                $lines[] = '    <video:video>';
                $lines[] = '      <video:thumbnail_loc>'.$this->xml($posterUrl).'</video:thumbnail_loc>';
                $lines[] = '      <video:title>'.$this->xml($title).'</video:title>';
                $lines[] = '      <video:description>'.$this->xml(
                    $this->truncate($description, 2048),
                ).'</video:description>';
                $lines[] = '      <video:content_loc>'.$this->xml($contentUrl).'</video:content_loc>';

                $duration = $this->videoSitemapDurationSeconds(
                    $item->video->durationMs,
                );
                if ($duration !== null) {
                    $lines[] = '      <video:duration>'.$duration.'</video:duration>';
                }

                $lines[] = '      <video:publication_date>'.$this->xml(
                    $item->video->publishedAt->format(DATE_ATOM),
                ).'</video:publication_date>';
                $lines[] = '    </video:video>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return $this->xmlResponse(implode("\n", $lines)."\n");
    }

    private function videoSitemapDurationSeconds(?int $durationMs): ?int
    {
        if ($durationMs === null || $durationMs <= 0) {
            return null;
        }

        $seconds = (int) ceil($durationMs / 1000);
        if ($seconds < 1 || $seconds > 28800) {
            return null;
        }

        return $seconds;
    }

    private function truncate(string $value, int $maximumCharacters): string
    {
        $length = iconv_strlen($value, 'UTF-8');
        if ($length === false || $length <= $maximumCharacters) {
            return $value;
        }

        $truncated = iconv_substr($value, 0, $maximumCharacters, 'UTF-8');

        return is_string($truncated) ? $truncated : $value;
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
