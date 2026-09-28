<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;

final class OrganizationAiProviderRegistry
{
    /** @var array<string,OrganizationAiProvider> */
    private array $providers = [];

    /**
     * @param iterable<OrganizationAiProvider> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            if (!$provider instanceof OrganizationAiProvider) {
                throw new \InvalidArgumentException(
                    'Organization AI registry accepts provider services only.',
                );
            }

            $descriptor = $provider->descriptor();
            if (isset($this->providers[$descriptor->key])) {
                throw new \InvalidArgumentException(
                    'Organization AI provider key is configured more than once.',
                );
            }

            $this->providers[$descriptor->key] = $provider;
        }

        ksort($this->providers);
    }

    public function provider(string $key): OrganizationAiProvider
    {
        $provider = $this->providers[$key] ?? null;
        if ($provider === null) {
            throw new \DomainException(
                'Organization AI provider is not configured.',
            );
        }

        return $provider;
    }

    /**
     * @return list<OrganizationAiProviderDescriptor>
     */
    public function available(): array
    {
        return array_map(
            static fn (OrganizationAiProvider $provider): OrganizationAiProviderDescriptor => $provider->descriptor(),
            array_values($this->providers),
        );
    }
}
