<?php

declare(strict_types=1);

namespace Mediarama\Collection\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mediarama\Media\Domain\MediaType;

final readonly class SmartCollectionRule
{
    public const VERSION = 1;
    public const MAX_DEPTH = 4;
    public const MAX_PREDICATES = 50;
    public const MAX_IN_VALUES = 100;

    /** @param array<string,mixed> $payload */
    private function __construct(private array $payload)
    {
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload, ['op', 'rules', 'version'], 'root');

        if (($payload['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported Smart Collection rule version.');
        }

        $predicateCount = 0;
        $root = self::normalizeGroup(
            [
                'op' => $payload['op'],
                'rules' => $payload['rules'],
            ],
            1,
            $predicateCount,
        );

        return new self([
            'version' => self::VERSION,
            ...$root,
        ]);
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

    /**
     * @param array<string,mixed> $node
     * @return array{op:string,rules:list<array<string,mixed>>}
     */
    private static function normalizeGroup(
        array $node,
        int $depth,
        int &$predicateCount,
    ): array {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Smart Collection rule nesting is too deep.');
        }

        self::assertExactKeys($node, ['op', 'rules'], 'group');

        $op = $node['op'] ?? null;
        if (!is_string($op) || !in_array($op, ['and', 'or'], true)) {
            throw new \InvalidArgumentException('Smart Collection groups require op=and|or.');
        }

        $rules = $node['rules'] ?? null;
        if (!is_array($rules) || !array_is_list($rules) || $rules === []) {
            throw new \InvalidArgumentException('Smart Collection groups require a non-empty rules list.');
        }

        $normalized = [];
        foreach ($rules as $child) {
            if (!is_array($child)) {
                throw new \InvalidArgumentException('Smart Collection rule nodes must be objects.');
            }

            if (array_key_exists('field', $child)) {
                ++$predicateCount;
                if ($predicateCount > self::MAX_PREDICATES) {
                    throw new \InvalidArgumentException('Smart Collection rule has too many predicates.');
                }

                $normalized[] = self::normalizePredicate($child);

                continue;
            }

            $normalized[] = self::normalizeGroup(
                $child,
                $depth + 1,
                $predicateCount,
            );
        }

        return ['op' => $op, 'rules' => $normalized];
    }

    /**
     * @param array<string,mixed> $node
     * @return array{field:string,operator:string,value:mixed}
     */
    private static function normalizePredicate(array $node): array
    {
        self::assertExactKeys($node, ['field', 'operator', 'value'], 'predicate');

        $fieldValue = $node['field'] ?? null;
        $operatorValue = $node['operator'] ?? null;

        if (!is_string($fieldValue) || !is_string($operatorValue)) {
            throw new \InvalidArgumentException('Smart Collection field/operator must be strings.');
        }

        $field = SmartCollectionField::tryFrom($fieldValue);
        $operator = SmartCollectionOperator::tryFrom($operatorValue);

        if ($field === null || $operator === null) {
            throw new \InvalidArgumentException('Unsupported Smart Collection field or operator.');
        }

        self::assertOperatorAllowed($field, $operator);

        return [
            'field' => $field->value,
            'operator' => $operator->value,
            'value' => self::normalizeValue($field, $operator, $node['value'] ?? null),
        ];
    }

    private static function assertOperatorAllowed(
        SmartCollectionField $field,
        SmartCollectionOperator $operator,
    ): void {
        $allowed = match ($field) {
            SmartCollectionField::MediaType,
            SmartCollectionField::Orientation => [
                SmartCollectionOperator::Equals,
                SmartCollectionOperator::NotEquals,
                SmartCollectionOperator::In,
            ],
            SmartCollectionField::CapturedAt,
            SmartCollectionField::RatingAverage => [
                SmartCollectionOperator::Equals,
                SmartCollectionOperator::NotEquals,
                SmartCollectionOperator::GreaterThanOrEqual,
                SmartCollectionOperator::LessThanOrEqual,
                SmartCollectionOperator::Between,
            ],
            SmartCollectionField::Creator,
            SmartCollectionField::CameraMake,
            SmartCollectionField::CameraModel,
            SmartCollectionField::Lens,
            SmartCollectionField::LocationName => [
                SmartCollectionOperator::Equals,
                SmartCollectionOperator::NotEquals,
                SmartCollectionOperator::In,
                SmartCollectionOperator::Contains,
            ],
            SmartCollectionField::Tag => [
                SmartCollectionOperator::HasTag,
            ],
        };

        if (!in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Operator "%s" is not allowed for Smart Collection field "%s".',
                $operator->value,
                $field->value,
            ));
        }
    }

    private static function normalizeValue(
        SmartCollectionField $field,
        SmartCollectionOperator $operator,
        mixed $value,
    ): mixed {
        return match ($field) {
            SmartCollectionField::MediaType => self::normalizeMediaType($operator, $value),
            SmartCollectionField::CapturedAt => self::normalizeDate($operator, $value),
            SmartCollectionField::Creator,
            SmartCollectionField::CameraMake,
            SmartCollectionField::CameraModel,
            SmartCollectionField::Lens,
            SmartCollectionField::LocationName => self::normalizeText($operator, $value),
            SmartCollectionField::Tag => self::normalizeTag($value),
            SmartCollectionField::RatingAverage => self::normalizeRating($operator, $value),
            SmartCollectionField::Orientation => self::normalizeOrientation($operator, $value),
        };
    }

    private static function normalizeMediaType(
        SmartCollectionOperator $operator,
        mixed $value,
    ): string|array {
        if ($operator === SmartCollectionOperator::In) {
            return self::normalizeList(
                $value,
                static function (mixed $item): string {
                    if (!is_string($item) || MediaType::tryFrom($item) === null) {
                        throw new \InvalidArgumentException('Invalid media_type rule value.');
                    }

                    return $item;
                },
            );
        }

        if (!is_string($value) || MediaType::tryFrom($value) === null) {
            throw new \InvalidArgumentException('Invalid media_type rule value.');
        }

        return $value;
    }

    private static function normalizeDate(
        SmartCollectionOperator $operator,
        mixed $value,
    ): string|array {
        if ($operator === SmartCollectionOperator::Between) {
            $values = self::normalizeFixedPair(
                $value,
                static fn (mixed $item): string => self::normalizeDateAtom($item),
            );

            if ($values[0] > $values[1]) {
                throw new \InvalidArgumentException('Smart Collection date range is reversed.');
            }

            return $values;
        }

        return self::normalizeDateAtom($value);
    }

    private static function normalizeDateAtom(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Smart Collection date values must be ISO 8601 strings.');
        }

        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \InvalidArgumentException('Smart Collection date values must use DATE_ATOM ISO 8601 format.');
        }

        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }

    private static function normalizeText(
        SmartCollectionOperator $operator,
        mixed $value,
    ): string|array {
        if ($operator === SmartCollectionOperator::In) {
            return self::normalizeList(
                $value,
                static fn (mixed $item): string => self::normalizeTextScalar($item),
            );
        }

        return self::normalizeTextScalar($value);
    }

    private static function normalizeTextScalar(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Smart Collection text values must be strings.');
        }

        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');

        if ($value === '' || $length === false || $length > 500) {
            throw new \InvalidArgumentException('Smart Collection text value is empty or too long.');
        }

        return $value;
    }

    private static function normalizeTag(mixed $value): string
    {
        $value = self::normalizeTextScalar($value);

        if (strlen($value) > 190) {
            throw new \InvalidArgumentException('Smart Collection tag slug is too long.');
        }

        return $value;
    }

    private static function normalizeRating(
        SmartCollectionOperator $operator,
        mixed $value,
    ): float|array {
        if ($operator === SmartCollectionOperator::Between) {
            $values = self::normalizeFixedPair(
                $value,
                static fn (mixed $item): float => self::normalizeRatingScalar($item),
            );

            if ($values[0] > $values[1]) {
                throw new \InvalidArgumentException('Smart Collection rating range is reversed.');
            }

            return $values;
        }

        return self::normalizeRatingScalar($value);
    }

    private static function normalizeRatingScalar(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException('Smart Collection rating values must be numeric.');
        }

        $value = (float) $value;
        if ($value < 1.0 || $value > 5.0) {
            throw new \InvalidArgumentException('Smart Collection rating values must be between 1 and 5.');
        }

        return $value;
    }

    private static function normalizeOrientation(
        SmartCollectionOperator $operator,
        mixed $value,
    ): string|array {
        $normalize = static function (mixed $item): string {
            if (
                !is_string($item)
                || !in_array($item, ['portrait', 'landscape', 'square'], true)
            ) {
                throw new \InvalidArgumentException('Invalid Smart Collection orientation value.');
            }

            return $item;
        };

        if ($operator === SmartCollectionOperator::In) {
            return self::normalizeList($value, $normalize);
        }

        return $normalize($value);
    }

    /**
     * @param callable(mixed):string $normalizer
     * @return list<string>
     */
    private static function normalizeList(mixed $value, callable $normalizer): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > self::MAX_IN_VALUES
        ) {
            throw new \InvalidArgumentException('Smart Collection in-list is empty, invalid or too large.');
        }

        $normalized = array_map($normalizer, $value);

        return array_values(array_unique($normalized, SORT_REGULAR));
    }

    /**
     * @template T
     * @param callable(mixed):T $normalizer
     * @return array{0:T,1:T}
     */
    private static function normalizeFixedPair(mixed $value, callable $normalizer): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
            throw new \InvalidArgumentException('Smart Collection range requires exactly two values.');
        }

        return [
            $normalizer($value[0]),
            $normalizer($value[1]),
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string> $expected
     */
    private static function assertExactKeys(
        array $node,
        array $expected,
        string $context,
    ): void {
        $keys = array_keys($node);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new \InvalidArgumentException(
                sprintf('Smart Collection %s has unsupported or missing keys.', $context),
            );
        }
    }
}
