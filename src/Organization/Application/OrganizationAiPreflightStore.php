<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;
use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;
use Symfony\Component\Uid\Uuid;

interface OrganizationAiPreflightStore
{
    /**
     * @param list<OrganizationAiCapability> $capabilities
     * @param list<OrganizationAiPreflightMedia> $media
     * @param array<string,int> $mediaTypeCounts
     * @param list<string> $excludedFields
     */
    public function create(
        Uuid $requesterId,
        OrganizationAiProviderDescriptor $provider,
        array $capabilities,
        OrganizationAiInputMode $inputMode,
        array $media,
        array $mediaTypeCounts,
        bool $includeCreator,
        bool $includeLocationName,
        array $excludedFields,
        ?string $costEstimate,
    ): Uuid;

    public function get(
        Uuid $requesterId,
        Uuid $preflightId,
    ): OrganizationAiPreflightResult;

    /** @return list<OrganizationAiPreflightMedia> */
    public function media(
        Uuid $requesterId,
        Uuid $preflightId,
    ): array;

    public function approve(
        Uuid $requesterId,
        Uuid $preflightId,
    ): void;

    public function claimApproved(
        Uuid $requesterId,
        Uuid $preflightId,
    ): OrganizationAiPreflightResult;

    public function complete(
        Uuid $requesterId,
        Uuid $preflightId,
        Uuid $runId,
    ): void;

    public function fail(
        Uuid $requesterId,
        Uuid $preflightId,
        string $failureCode,
    ): void;
}
