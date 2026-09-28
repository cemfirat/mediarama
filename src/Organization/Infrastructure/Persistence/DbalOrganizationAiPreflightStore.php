<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Organization\Application\OrganizationAiPreflightMedia;
use Mediarama\Organization\Application\OrganizationAiPreflightResult;
use Mediarama\Organization\Application\OrganizationAiPreflightStore;
use Mediarama\Organization\Domain\OrganizationAiCapability;
use Mediarama\Organization\Domain\OrganizationAiInputMode;
use Mediarama\Organization\Domain\OrganizationAiPreflightStatus;
use Mediarama\Organization\Domain\OrganizationAiProviderDescriptor;
use Mediarama\Organization\Domain\OrganizationProducer;
use Mediarama\Organization\Domain\OrganizationProducerKind;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOrganizationAiPreflightStore implements OrganizationAiPreflightStore
{
    private const MAX_MEDIA = 50000;

    public function __construct(private Connection $connection)
    {
    }

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
    ): Uuid {
        $media = $this->mediaScope($media);
        $capabilityValues = array_map(
            static fn (OrganizationAiCapability $capability): string => $capability->value,
            $capabilities,
        );
        $excludedFields = $this->strings($excludedFields, 'excluded fields');

        $presentationCount = count(array_filter(
            $media,
            static fn (OrganizationAiPreflightMedia $item): bool => $item->sendPresentation,
        ));

        $mediaCount = count($media);
        if (array_sum($mediaTypeCounts) !== $mediaCount) {
            throw new \InvalidArgumentException(
                'Organization AI media type counts do not match the preflight scope.',
            );
        }

        return $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $provider,
            $capabilityValues,
            $inputMode,
            $media,
            $mediaTypeCounts,
            $mediaCount,
            $presentationCount,
            $includeCreator,
            $includeLocationName,
            $excludedFields,
            $costEstimate,
        ): Uuid {
            $id = Uuid::v7();
            $now = (new DateTimeImmutable())->format(DATE_ATOM);
            $producer = $provider->producer();

            $connection->insert(
                'organization_ai_preflights',
                [
                    'id' => $id->toRfc4122(),
                    'requester_id' => $requesterId->toRfc4122(),
                    'provider_key' => $provider->key,
                    'producer_kind' => $producer->kind->value,
                    'provider_name' => $producer->providerName,
                    'model_name' => $producer->modelName,
                    'model_version' => $producer->modelVersion,
                    'requested_capabilities' => json_encode(
                        $capabilityValues,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ),
                    'input_mode' => $inputMode->value,
                    'media_type_counts' => json_encode(
                        $mediaTypeCounts,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ),
                    'media_count' => $mediaCount,
                    'presentation_media_count' => $presentationCount,
                    'include_creator' => $includeCreator,
                    'include_location_name' => $includeLocationName,
                    'excluded_fields' => json_encode(
                        $excludedFields,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ),
                    'cost_estimate' => $costEstimate,
                    'privacy_note' => $provider->privacyNote,
                    'retention_note' => $provider->retentionNote,
                    'status' => OrganizationAiPreflightStatus::PendingApproval->value,
                    'run_id' => null,
                    'failure_code' => null,
                    'approved_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'include_creator' => ParameterType::BOOLEAN,
                    'include_location_name' => ParameterType::BOOLEAN,
                ],
            );

            $this->insertMedia(
                $connection,
                $id,
                $media,
            );

            return $id;
        });
    }

    public function get(
        Uuid $requesterId,
        Uuid $preflightId,
    ): OrganizationAiPreflightResult {
        $row = $this->connection->fetchAssociative(
            $this->select().'
WHERE p.id = :preflight
  AND p.requester_id = :requester',
            [
                'preflight' => $preflightId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
            ],
        );

        if ($row === false) {
            throw new \DomainException(
                'Organization AI preflight is unavailable.',
            );
        }

        return $this->map($row);
    }

    public function forRun(
        Uuid $requesterId,
        Uuid $runId,
    ): ?OrganizationAiPreflightResult {
        $row = $this->connection->fetchAssociative(
            $this->select().'
WHERE p.run_id = :run
  AND p.requester_id = :requester',
            [
                'run' => $runId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
            ],
        );

        return $row === false ? null : $this->map($row);
    }

    public function media(
        Uuid $requesterId,
        Uuid $preflightId,
    ): array {
        $this->get($requesterId, $preflightId);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT media_id, send_presentation
             FROM organization_ai_preflight_media
             WHERE preflight_id = :preflight
             ORDER BY position ASC',
            ['preflight' => $preflightId->toRfc4122()],
        );

        return array_map(
            fn (array $row): OrganizationAiPreflightMedia => new OrganizationAiPreflightMedia(
                Uuid::fromString((string) $row['media_id']),
                $this->toBoolean($row['send_presentation']),
            ),
            $rows,
        );
    }

    public function approve(
        Uuid $requesterId,
        Uuid $preflightId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $preflightId,
        ): void {
            $row = $this->lock(
                $connection,
                $requesterId,
                $preflightId,
            );
            $status = OrganizationAiPreflightStatus::from(
                (string) $row['status'],
            );

            if ($status === OrganizationAiPreflightStatus::Approved) {
                return;
            }

            if ($status !== OrganizationAiPreflightStatus::PendingApproval) {
                throw new \DomainException(
                    'Organization AI preflight cannot be approved in its current state.',
                );
            }

            $connection->executeStatement(
                'UPDATE organization_ai_preflights
                 SET status = :status,
                     approved_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :preflight',
                [
                    'status' => OrganizationAiPreflightStatus::Approved->value,
                    'preflight' => $preflightId->toRfc4122(),
                ],
            );
        });
    }

    public function claimApproved(
        Uuid $requesterId,
        Uuid $preflightId,
    ): OrganizationAiPreflightResult {
        return $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $preflightId,
        ): OrganizationAiPreflightResult {
            $row = $this->lock(
                $connection,
                $requesterId,
                $preflightId,
            );

            if (
                (string) $row['status']
                !== OrganizationAiPreflightStatus::Approved->value
            ) {
                throw new \DomainException(
                    'Organization AI provider execution requires an approved preflight.',
                );
            }

            $connection->executeStatement(
                'UPDATE organization_ai_preflights
                 SET status = :status,
                     started_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :preflight',
                [
                    'status' => OrganizationAiPreflightStatus::Executing->value,
                    'preflight' => $preflightId->toRfc4122(),
                ],
            );

            $updated = $connection->fetchAssociative(
                $this->select().' WHERE p.id = :preflight',
                ['preflight' => $preflightId->toRfc4122()],
            );

            if ($updated === false) {
                throw new \RuntimeException(
                    'Organization AI preflight disappeared during execution claim.',
                );
            }

            return $this->map($updated);
        });
    }

    public function complete(
        Uuid $requesterId,
        Uuid $preflightId,
        Uuid $runId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $preflightId,
            $runId,
        ): void {
            $row = $this->lock(
                $connection,
                $requesterId,
                $preflightId,
            );

            if (
                (string) $row['status']
                !== OrganizationAiPreflightStatus::Executing->value
            ) {
                throw new \DomainException(
                    'Organization AI preflight is not executing.',
                );
            }

            $runOwner = $connection->fetchOne(
                'SELECT requester_id
                 FROM organization_runs
                 WHERE id = :run',
                ['run' => $runId->toRfc4122()],
            );

            if (
                $runOwner === false
                || (string) $runOwner !== $requesterId->toRfc4122()
            ) {
                throw new \InvalidArgumentException(
                    'Organization AI run does not belong to the preflight requester.',
                );
            }

            $connection->executeStatement(
                'UPDATE organization_ai_preflights
                 SET status = :status,
                     run_id = :run,
                     completed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :preflight',
                [
                    'status' => OrganizationAiPreflightStatus::Completed->value,
                    'run' => $runId->toRfc4122(),
                    'preflight' => $preflightId->toRfc4122(),
                ],
            );
        });
    }

    public function fail(
        Uuid $requesterId,
        Uuid $preflightId,
        string $failureCode,
    ): void {
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $failureCode) !== 1) {
            throw new \InvalidArgumentException(
                'Organization AI preflight failure code is invalid.',
            );
        }

        $this->connection->transactional(function (Connection $connection) use (
            $requesterId,
            $preflightId,
            $failureCode,
        ): void {
            $row = $this->lock(
                $connection,
                $requesterId,
                $preflightId,
            );
            $status = OrganizationAiPreflightStatus::from(
                (string) $row['status'],
            );

            if ($status === OrganizationAiPreflightStatus::Failed) {
                return;
            }

            if ($status !== OrganizationAiPreflightStatus::Executing) {
                throw new \DomainException(
                    'Only an executing Organization AI preflight can fail.',
                );
            }

            $connection->executeStatement(
                'UPDATE organization_ai_preflights
                 SET status = :status,
                     failure_code = :failure_code,
                     completed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :preflight',
                [
                    'status' => OrganizationAiPreflightStatus::Failed->value,
                    'failure_code' => $failureCode,
                    'preflight' => $preflightId->toRfc4122(),
                ],
            );
        });
    }

    private function select(): string
    {
        return <<<'SQL'
SELECT
    p.id,
    p.requester_id,
    p.provider_key,
    p.producer_kind,
    p.provider_name,
    p.model_name,
    p.model_version,
    p.requested_capabilities,
    p.input_mode,
    p.media_type_counts,
    p.media_count,
    p.presentation_media_count,
    p.include_creator,
    p.include_location_name,
    p.excluded_fields,
    p.cost_estimate,
    p.privacy_note,
    p.retention_note,
    p.status,
    p.run_id,
    p.failure_code,
    p.approved_at,
    p.started_at,
    p.completed_at,
    p.created_at,
    p.updated_at
FROM organization_ai_preflights p
SQL;
    }

    /** @return array<string,mixed> */
    private function lock(
        Connection $connection,
        Uuid $requesterId,
        Uuid $preflightId,
    ): array {
        $row = $connection->fetchAssociative(
            'SELECT *
             FROM organization_ai_preflights
             WHERE id = :preflight
               AND requester_id = :requester
             FOR UPDATE',
            [
                'preflight' => $preflightId->toRfc4122(),
                'requester' => $requesterId->toRfc4122(),
            ],
        );

        if ($row === false) {
            throw new \DomainException(
                'Organization AI preflight is unavailable.',
            );
        }

        return $row;
    }

    /**
     * @param list<OrganizationAiPreflightMedia> $media
     * @return list<OrganizationAiPreflightMedia>
     */
    private function mediaScope(array $media): array
    {
        if ($media === [] || count($media) > self::MAX_MEDIA) {
            throw new \InvalidArgumentException(
                'Organization AI preflight scope must contain 1-50000 MediaAssets.',
            );
        }

        $seen = [];
        foreach ($media as $item) {
            if (!$item instanceof OrganizationAiPreflightMedia) {
                throw new \InvalidArgumentException(
                    'Organization AI preflight scope is invalid.',
                );
            }

            $id = $item->mediaId->toRfc4122();
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException(
                    'Organization AI preflight scope contains duplicate MediaAssets.',
                );
            }

            $seen[$id] = true;
        }

        return array_values($media);
    }

    /**
     * @param list<OrganizationAiPreflightMedia> $media
     */
    private function insertMedia(
        Connection $connection,
        Uuid $preflightId,
        array $media,
    ): void {
        foreach (array_chunk($media, 500) as $chunkIndex => $chunk) {
            $values = [];
            $parameters = ['preflight' => $preflightId->toRfc4122()];
            $types = [];
            $offset = $chunkIndex * 500;

            foreach ($chunk as $index => $item) {
                $idName = 'media_'.$index;
                $sendName = 'send_'.$index;
                $values[] = sprintf(
                    '(:preflight, :%s, %d, :%s)',
                    $idName,
                    $offset + $index,
                    $sendName,
                );
                $parameters[$idName] = $item->mediaId->toRfc4122();
                $parameters[$sendName] = $item->sendPresentation;
                $types[$sendName] = ParameterType::BOOLEAN;
            }

            $connection->executeStatement(
                'INSERT INTO organization_ai_preflight_media (
                    preflight_id,
                    media_id,
                    position,
                    send_presentation
                 ) VALUES '.implode(', ', $values),
                $parameters,
                $types,
            );
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function strings(array $values, string $label): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    'Organization AI '.$label.' are invalid.',
                );
            }

            $value = trim($value);
            if ($value === '' || strlen($value) > 120) {
                throw new \InvalidArgumentException(
                    'Organization AI '.$label.' contain an invalid value.',
                );
            }

            $result[$value] = $value;
        }

        ksort($result);

        return array_values($result);
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): OrganizationAiPreflightResult
    {
        $capabilityValues = $this->jsonList(
            $row['requested_capabilities'],
            'requested capabilities',
        );
        $capabilities = array_map(
            static fn (string $value): OrganizationAiCapability => OrganizationAiCapability::from($value),
            $capabilityValues,
        );

        $mediaTypeCounts = $this->jsonObject(
            $row['media_type_counts'],
            'media type counts',
        );
        $normalizedCounts = [];
        foreach ($mediaTypeCounts as $type => $count) {
            if (!is_int($count) || $count < 0) {
                throw new \RuntimeException(
                    'Persisted Organization AI media type counts are invalid.',
                );
            }
            $normalizedCounts[(string) $type] = $count;
        }

        return new OrganizationAiPreflightResult(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['requester_id']),
            (string) $row['provider_key'],
            OrganizationProducer::fromPersisted(
                OrganizationProducerKind::from((string) $row['producer_kind']),
                (string) $row['provider_name'],
                (string) $row['model_name'],
                $row['model_version'] !== null
                    ? (string) $row['model_version']
                    : null,
            ),
            $capabilities,
            OrganizationAiInputMode::from((string) $row['input_mode']),
            (int) $row['media_count'],
            $normalizedCounts,
            (int) $row['presentation_media_count'],
            $this->toBoolean($row['include_creator']),
            $this->toBoolean($row['include_location_name']),
            $this->jsonList($row['excluded_fields'], 'excluded fields'),
            $row['cost_estimate'] !== null ? (string) $row['cost_estimate'] : null,
            $row['privacy_note'] !== null ? (string) $row['privacy_note'] : null,
            $row['retention_note'] !== null ? (string) $row['retention_note'] : null,
            OrganizationAiPreflightStatus::from((string) $row['status']),
            $row['run_id'] !== null
                ? Uuid::fromString((string) $row['run_id'])
                : null,
            $row['failure_code'] !== null
                ? (string) $row['failure_code']
                : null,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
            $row['approved_at'] !== null
                ? new DateTimeImmutable((string) $row['approved_at'])
                : null,
            $row['started_at'] !== null
                ? new DateTimeImmutable((string) $row['started_at'])
                : null,
            $row['completed_at'] !== null
                ? new DateTimeImmutable((string) $row['completed_at'])
                : null,
        );
    }

    /** @return list<string> */
    private function jsonList(mixed $value, string $label): array
    {
        $decoded = $this->decode($value);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException(
                'Persisted Organization AI '.$label.' are invalid.',
            );
        }

        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw new \RuntimeException(
                    'Persisted Organization AI '.$label.' are invalid.',
                );
            }
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value, string $label): array
    {
        $decoded = $this->decode($value);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(
                'Persisted Organization AI '.$label.' are invalid.',
            );
        }

        return $decoded;
    }

    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            return json_decode(
                $value,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        return $value;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower((string) $value),
            ['1', 't', 'true', 'yes', 'on'],
            true,
        );
    }
}
