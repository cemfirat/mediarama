<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('mediarama.organization_ai_provider')]
interface OrganizationAiProvider
{
    public function descriptor(): OrganizationAiProviderDescriptor;

    /**
     * Local, side-effect-free estimate only. This method must never send media
     * or metadata to an external provider.
     */
    public function estimateCost(
        OrganizationAiRequestSummary $summary,
    ): ?string;

    /**
     * This is the provider inference boundary. Implementations must map any
     * vendor response to Mediarama-owned proposal candidates and never expose
     * raw provider payloads as durable domain state.
     *
     * @return list<OrganizationProposalCandidate>
     */
    public function propose(
        OrganizationAiRequest $request,
    ): array;
}
