<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use DateTimeImmutable;
use Mediarama\Collection\Domain\SmartCollectionField;
use Mediarama\Collection\Domain\SmartCollectionOperator;
use Mediarama\Collection\Domain\SmartCollectionRule;

final class SmartCollectionRuleFormFactory
{
    /**
     * @param array<int|string,mixed> $fields
     * @param array<int|string,mixed> $operators
     * @param array<int|string,mixed> $values
     * @param array<int|string,mixed> $secondaryValues
     */
    public function create(
        string $groupOperator,
        array $fields,
        array $operators,
        array $values,
        array $secondaryValues,
    ): SmartCollectionRule {
        if (!in_array($groupOperator, ['and', 'or'], true)) {
            throw new \InvalidArgumentException('Smart rule group must be AND or OR.');
        }

        $rules = [];
        $indexes = array_unique([
            ...array_keys($fields),
            ...array_keys($operators),
            ...array_keys($values),
            ...array_keys($secondaryValues),
        ]);

        foreach ($indexes as $index) {
            $fieldValue = trim((string) ($fields[$index] ?? ''));
            $operatorValue = trim((string) ($operators[$index] ?? ''));
            $value = $values[$index] ?? null;
            $secondary = $secondaryValues[$index] ?? null;

            if (
                $fieldValue === ''
                && $operatorValue === ''
                && trim((string) $value) === ''
                && trim((string) $secondary) === ''
            ) {
                continue;
            }

            $field = SmartCollectionField::tryFrom($fieldValue);
            $operator = SmartCollectionOperator::tryFrom($operatorValue);

            if ($field === null || $operator === null) {
                throw new \InvalidArgumentException('Choose a valid Smart Collection field and operator.');
            }

            $rules[] = [
                'field' => $field->value,
                'operator' => $operator->value,
                'value' => $this->formValue(
                    $field,
                    $operator,
                    $value,
                    $secondary,
                ),
            ];
        }

        if ($rules === []) {
            throw new \InvalidArgumentException('Add at least one Smart Collection rule.');
        }

        return SmartCollectionRule::fromArray([
            'version' => SmartCollectionRule::VERSION,
            'op' => $groupOperator,
            'rules' => $rules,
        ]);
    }

    /**
     * @return null|list<array{field:string,operator:string,value:string,secondary:string}>
     */
    public function editableRows(SmartCollectionRule $rule): ?array
    {
        $payload = $rule->payload();
        $rows = [];

        foreach ($payload['rules'] as $node) {
            if (!isset($node['field'], $node['operator']) || isset($node['op'])) {
                return null;
            }

            $value = $node['value'] ?? '';
            $primary = '';
            $secondary = '';

            if ($node['operator'] === SmartCollectionOperator::Between->value) {
                if (!is_array($value) || count($value) !== 2) {
                    return null;
                }

                $primary = (string) $value[0];
                $secondary = (string) $value[1];
            } elseif ($node['operator'] === SmartCollectionOperator::In->value) {
                if (!is_array($value)) {
                    return null;
                }

                $primary = implode(', ', array_map(
                    static fn (mixed $item): string => (string) $item,
                    $value,
                ));
            } else {
                $primary = (string) $value;
            }

            $rows[] = [
                'field' => (string) $node['field'],
                'operator' => (string) $node['operator'],
                'value' => $primary,
                'secondary' => $secondary,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{field:string,operator:string,value:string,secondary:string}>
     */
    public function blankRows(int $count = 5): array
    {
        if ($count < 1 || $count > 20) {
            throw new \InvalidArgumentException('Invalid Smart Collection form row count.');
        }

        return array_fill(0, $count, [
            'field' => '',
            'operator' => '',
            'value' => '',
            'secondary' => '',
        ]);
    }

    private function formValue(
        SmartCollectionField $field,
        SmartCollectionOperator $operator,
        mixed $value,
        mixed $secondary,
    ): mixed {
        if ($operator === SmartCollectionOperator::In) {
            $items = array_values(array_filter(
                array_map(
                    static fn (string $item): string => trim($item),
                    explode(',', (string) $value),
                ),
                static fn (string $item): bool => $item !== '',
            ));

            return array_map(
                fn (string $item): mixed => $this->scalarValue($field, $item),
                $items,
            );
        }

        if ($operator === SmartCollectionOperator::Between) {
            return [
                $this->scalarValue($field, $value),
                $this->scalarValue($field, $secondary),
            ];
        }

        return $this->scalarValue($field, $value);
    }

    private function scalarValue(
        SmartCollectionField $field,
        mixed $value,
    ): mixed {
        $value = trim((string) $value);

        if ($field === SmartCollectionField::RatingAverage) {
            if ($value === '' || !is_numeric($value)) {
                return $value;
            }

            return (float) $value;
        }

        if ($field === SmartCollectionField::CapturedAt) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                $date = DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    $value,
                    new \DateTimeZone('UTC'),
                );

                if ($date !== false) {
                    return $date->format(DATE_ATOM);
                }
            }
        }

        return $value;
    }
}
