<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use DateTimeImmutable;
use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;
use Mediarama\Organization\Domain\OrganizationAiPreflightStatus;
use Mediarama\Organization\Domain\OrganizationProducer;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationAiPreflightResult
{
    /**
     * @param list<OrganizationAiCapability> $capabilities
     * @param array<string,int> $mediaTypeCounts
     * @param list<string> $excludedFields
     */
    public function __construct(
        public Uuid $id,
        public Uuid $requesterId,
        public string $providerKey,
        public OrganizationProducer $producer,
        public array $capabilities,
        public OrganizationAiInputMode $inputMode,
        public int $mediaCount,
        public array $mediaTypeCounts,
        public int $presentationMediaCount,
        public bool $includeCreator,
        public bool $includeLocationName,
        public array $excludedFields,
        public ?string $costEstimate,
        public ?string $privacyNote,
        public ?string $retentionNote,
        public OrganizationAiPreflightStatus $status,
        public ?Uuid $runId,
        public ?string $failureCode,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $approvedAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
    ) {
    }

    public function summary(): OrganizationAiRequestSummary
    {
        return new OrganizationAiRequestSummary(
            $this->capabilities,
            $this->inputMode,
            $this->mediaCount,
            $this->mediaTypeCounts,
            $this->presentationMediaCount,
            $this->includeCreator,
            $this->includeLocationName,
        );
    }

    public function sendsOriginals(): bool
    {
        return false;
    }
}
