<?php

declare(strict_types=1);

namespace Mediarama\Seo\Infrastructure\Http;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicUrlGenerator
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly UrlGeneratorInterface $routes,
        string $baseUrl,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);

        if (
            $baseUrl === ''
            || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
        ) {
            throw new \InvalidArgumentException(
                'PUBLIC_BASE_URL must be an absolute http(s) origin without a path, query or fragment.',
            );
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * @param array<string,mixed> $parameters
     */
    public function route(string $name, array $parameters = []): string
    {
        return $this->baseUrl.$this->routes->generate(
            $name,
            $parameters,
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
