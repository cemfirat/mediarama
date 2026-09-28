<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

use Mediarama\Collection\Domain\SmartCollectionRule;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationProposalPayload
{
    public const VERSION = 1;

    /** @param array<string,mixed> $payload */
    private function __construct(
        public OrganizationProposalType $type,
        private array $payload,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(
        OrganizationProposalType $type,
        array $payload,
    ): self {
        $normalized = match ($type) {
            OrganizationProposalType::SmartCollection => self::smartCollection($payload),
            OrganizationProposalType::ManualCollection => self::manualCollection($payload),
            OrganizationProposalType::Tag => self::tag($payload),
            OrganizationProposalType::ReviewBucket => self::reviewBucket($payload),
            OrganizationProposalType::TitleDescription => self::titleDescription($payload),
            OrganizationProposalType::Cover => self::cover($payload),
        };

        return new self($type, $normalized);
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function toJson(): string
    {
        return json_encode(
            $this->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private static function smartCollection(array $payload): array
    {
        self::assertExactKeys(
            $payload,
            ['description', 'rule', 'title', 'version'],
            'smart_collection',
        );
        self::assertVersion($payload);

        if (!is_array($payload['rule'] ?? null)) {
            throw new \InvalidArgumentException(
                'Smart Collection proposal rule must be an object.',
            );
        }

        $rule = SmartCollectionRule::fromArray($payload['rule']);

        return [
            'version' => self::VERSION,
            'title' => self::text($payload['title'] ?? null, 'title', 200),
            'description' => self::nullableText(
                $payload['description'] ?? null,
                'description',
                5000,
            ),
            'rule' => $rule->payload(),
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private static function manualCollection(array $payload): array
    {
        self::assertExactKeys(
            $payload,
            ['description', 'title', 'version'],
            'manual_collection',
        );
        self::assertVersion($payload);

        return [
            'version' => self::VERSION,
            'title' => self::text($payload['title'] ?? null, 'title', 200),
            'description' => self::nullableText(
                $payload['description'] ?? null,
                'description',
                5000,
            ),
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private static function tag(array $payload): array
    {
        self::assertExactKeys($payload, ['name', 'version'], 'tag');
        self::assertVersion($payload);

        return [
            'version' => self::VERSION,
            'name' => self::text($payload['name'] ?? null, 'tag name', 160),
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private static function reviewBucket(array $payload): array
    {
        self::assertExactKeys(
            $payload,
            ['description', 'title', 'version'],
            'review_bucket',
        );
        self::assertVersion($payload);

        return [
            'version' => self::VERSION,
            'title' => self::text($payload['title'] ?? null, 'title', 200),
            'description' => self::nullableText(
                $payload['description'] ?? null,
                'description',
                5000,
            ),
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private static function titleDescription(array $payload): array
    {
        self::assertExactKeys(
            $payload,
            ['description', 'target_id', 'target_type', 'title', 'version'],
            'title_description',
        );
        self::assertVersion($payload);

        $targetType = $payload['target_type'] ?? null;
        if (!is_string($targetType) || !in_array(
            $targetType,
            ['media', 'collection'],
            true,
        )) {
            throw new \InvalidArgumentException(
                'Title/description proposal target_type must be media or collection.',
            );
        }

        $title = self::nullableText(
            $payload['title'] ?? null,
            'title',
            200,
        );
        $description = self::nullableText(
            $payload['description'] ?? null,
            'description',
            5000,
        );

        if ($title === null && $description === null) {
            throw new \InvalidArgumentException(
                'Title/description proposal must change at least one field.',
            );
        }

        return [
            'version' => self::VERSION,
            'target_type' => $targetType,
            'target_id' => self::uuid($payload['target_id'] ?? null, 'target_id'),
            'title' => $title,
            'description' => $description,
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private static function cover(array $payload): array
    {
        self::assertExactKeys(
            $payload,
            ['collection_id', 'media_id', 'version'],
            'cover',
        );
        self::assertVersion($payload);

        return [
            'version' => self::VERSION,
            'collection_id' => self::uuid(
                $payload['collection_id'] ?? null,
                'collection_id',
            ),
            'media_id' => self::uuid(
                $payload['media_id'] ?? null,
                'media_id',
            ),
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function assertVersion(array $payload): void
    {
        if (($payload['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException(
                'Unsupported organization proposal payload version.',
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $expected
     */
    private static function assertExactKeys(
        array $payload,
        array $expected,
        string $type,
    ): void {
        $actual = array_keys($payload);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'Organization %s proposal has unsupported or missing fields.',
                $type,
            ));
        }
    }

    private static function text(
        mixed $value,
        string $label,
        int $maximumLength,
    ): string {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Organization proposal '.$label.' must be text.',
            );
        }

        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');

        if (
            $value === ''
            || $length === false
            || $length > $maximumLength
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Organization proposal %s must contain 1-%d characters.',
                $label,
                $maximumLength,
            ));
        }

        return $value;
    }

    private static function nullableText(
        mixed $value,
        string $label,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Organization proposal '.$label.' must be text or null.',
            );
        }

        $value = trim($value);

        return $value === ''
            ? null
            : self::text($value, $label, $maximumLength);
    }

    private static function uuid(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Organization proposal '.$label.' must be a UUID.',
            );
        }

        try {
            return Uuid::fromString($value)->toRfc4122();
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(
                'Organization proposal '.$label.' must be a valid UUID.',
            );
        }
    }
}
