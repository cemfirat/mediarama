<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Mediarama\Organization\Domain\OrganizationProposalPayload;
use Mediarama\Organization\Domain\OrganizationProposalType;

final class OrganizationProposalPayloadEditor
{
    /**
     * @param array<string,mixed> $changes
     */
    public function edit(
        OrganizationProposalPayload $current,
        array $changes,
    ): OrganizationProposalPayload {
        $data = $current->payload();

        return match ($current->type) {
            OrganizationProposalType::SmartCollection => $this->collectionLike(
                $current->type,
                $data,
                $changes,
                keepRule: true,
            ),
            OrganizationProposalType::ManualCollection,
            OrganizationProposalType::ReviewBucket => $this->collectionLike(
                $current->type,
                $data,
                $changes,
                keepRule: false,
            ),
            OrganizationProposalType::Tag => $this->tag(
                $data,
                $changes,
            ),
            OrganizationProposalType::TitleDescription => $this->titleDescription(
                $data,
                $changes,
            ),
            OrganizationProposalType::Cover => $this->cover(
                $data,
                $changes,
            ),
        };
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $changes
     */
    private function collectionLike(
        OrganizationProposalType $type,
        array $data,
        array $changes,
        bool $keepRule,
    ): OrganizationProposalPayload {
        $this->allowed($changes, ['title', 'description']);

        if (array_key_exists('title', $changes)) {
            $data['title'] = $this->string($changes['title'], 'title');
        }
        if (array_key_exists('description', $changes)) {
            $data['description'] = $this->nullableString(
                $changes['description'],
                'description',
            );
        }

        if (!$keepRule) {
            unset($data['rule']);
        }

        return OrganizationProposalPayload::fromArray($type, $data);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $changes
     */
    private function tag(
        array $data,
        array $changes,
    ): OrganizationProposalPayload {
        $this->allowed($changes, ['name']);

        if (array_key_exists('name', $changes)) {
            $data['name'] = $this->string($changes['name'], 'tag name');
        }

        return OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Tag,
            $data,
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $changes
     */
    private function titleDescription(
        array $data,
        array $changes,
    ): OrganizationProposalPayload {
        $this->allowed($changes, ['title', 'description']);

        if (array_key_exists('title', $changes)) {
            $data['title'] = $this->nullableString(
                $changes['title'],
                'title',
            );
        }
        if (array_key_exists('description', $changes)) {
            $data['description'] = $this->nullableString(
                $changes['description'],
                'description',
            );
        }

        return OrganizationProposalPayload::fromArray(
            OrganizationProposalType::TitleDescription,
            $data,
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $changes
     */
    private function cover(
        array $data,
        array $changes,
    ): OrganizationProposalPayload {
        $this->allowed($changes, ['media_id']);

        if (array_key_exists('media_id', $changes)) {
            $data['media_id'] = $this->string(
                $changes['media_id'],
                'cover MediaAsset',
            );
        }

        return OrganizationProposalPayload::fromArray(
            OrganizationProposalType::Cover,
            $data,
        );
    }

    /**
     * @param array<string,mixed> $changes
     * @param list<string> $allowed
     */
    private function allowed(array $changes, array $allowed): void
    {
        foreach (array_keys($changes) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException(
                    'Organization proposal edit contains an unsupported field.',
                );
            }
        }
    }

    private function string(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Organization proposal '.$label.' edit must be text.',
            );
        }

        return trim($value);
    }

    private function nullableString(mixed $value, string $label): ?string
    {
        $value = $this->string($value, $label);

        return $value === '' ? null : $value;
    }
}
