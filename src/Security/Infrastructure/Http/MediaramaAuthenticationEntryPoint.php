<?php

declare(strict_types=1);

namespace Mediarama\Security\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class MediaramaAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return new JsonResponse(
                ['error' => 'authentication_required'],
                Response::HTTP_UNAUTHORIZED,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new RedirectResponse($this->urls->generate('app_login'));
    }
}
