<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Mediarama\Collection\Domain\SmartCollectionField;
use Mediarama\Collection\Domain\SmartCollectionOperator;
use Mediarama\Collection\Domain\SmartCollectionRule;

final class SmartCollectionRuleCompiler
{
    public function compile(
        SmartCollectionRule $rule,
        string $mediaAlias = 'm',
    ): SmartCollectionSqlPredicate {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $mediaAlias) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL media alias.');
        }

        $payload = $rule->payload();
        $parameters = [];
        $counter = 0;

        $sql = $this->compileGroup(
            [
                'op' => $payload['op'],
                'rules' => $payload['rules'],
            ],
            $mediaAlias,
            $parameters,
            $counter,
        );

        return new SmartCollectionSqlPredicate($sql, $parameters);
    }

    /**
     * @param array{op:string,rules:list<array<string,mixed>>} $group
     * @param array<string,mixed> $parameters
     */
    private function compileGroup(
        array $group,
        string $alias,
        array &$parameters,
        int &$counter,
    ): string {
        $parts = [];

        foreach ($group['rules'] as $node) {
            if (array_key_exists('field', $node)) {
                $parts[] = $this->compilePredicate(
                    $node,
                    $alias,
                    $parameters,
                    $counter,
                );
            } else {
                /** @var array{op:string,rules:list<array<string,mixed>>} $node */
                $parts[] = $this->compileGroup(
                    $node,
                    $alias,
                    $parameters,
                    $counter,
                );
            }
        }

        $join = $group['op'] === 'and' ? ' AND ' : ' OR ';

        return '('.implode($join, $parts).')';
    }

    /**
     * @param array{field:string,operator:string,value:mixed} $predicate
     * @param array<string,mixed> $parameters
     */
    private function compilePredicate(
        array $predicate,
        string $alias,
        array &$parameters,
        int &$counter,
    ): string {
        $field = SmartCollectionField::from($predicate['field']);
        $operator = SmartCollectionOperator::from($predicate['operator']);
        $value = $predicate['value'];

        return match ($field) {
            SmartCollectionField::MediaType => $this->compileScalar(
                $alias.'.media_type',
                $operator,
                $value,
                $parameters,
                $counter,
                false,
            ),
            SmartCollectionField::CapturedAt => $this->compileScalar(
                $alias.'.captured_at',
                $operator,
                $value,
                $parameters,
                $counter,
                false,
            ),
            SmartCollectionField::Creator,
            SmartCollectionField::CameraMake,
            SmartCollectionField::CameraModel,
            SmartCollectionField::Lens,
            SmartCollectionField::LocationName => $this->compileText(
                $alias.'.'.$this->textColumn($field),
                $operator,
                $value,
                $parameters,
                $counter,
            ),
            SmartCollectionField::Tag => $this->compileTag(
                $alias,
                (string) $value,
                $parameters,
                $counter,
            ),
            SmartCollectionField::RatingAverage => $this->compileScalar(
                '(SELECT AVG(r.value)::double precision FROM ratings r WHERE r.media_id = '.$alias.'.id)',
                $operator,
                $value,
                $parameters,
                $counter,
                false,
            ),
            SmartCollectionField::Orientation => $this->compileOrientation(
                $alias,
                $operator,
                $value,
            ),
        };
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function compileScalar(
        string $expression,
        SmartCollectionOperator $operator,
        mixed $value,
        array &$parameters,
        int &$counter,
        bool $caseInsensitive,
    ): string {
        if ($operator === SmartCollectionOperator::In) {
            if (!is_array($value)) {
                throw new \LogicException('Normalized in-list must be an array.');
            }

            $placeholders = [];
            foreach ($value as $item) {
                $placeholder = $this->parameter($item, $parameters, $counter);
                $placeholders[] = $caseInsensitive
                    ? 'LOWER('.$placeholder.')'
                    : $placeholder;
            }

            $left = $caseInsensitive ? 'LOWER('.$expression.')' : $expression;

            return $left.' IN ('.implode(', ', $placeholders).')';
        }

        if ($operator === SmartCollectionOperator::Between) {
            if (!is_array($value) || count($value) !== 2) {
                throw new \LogicException('Normalized between value must contain two values.');
            }

            $from = $this->parameter($value[0], $parameters, $counter);
            $until = $this->parameter($value[1], $parameters, $counter);

            return $expression.' BETWEEN '.$from.' AND '.$until;
        }

        $placeholder = $this->parameter($value, $parameters, $counter);
        $left = $caseInsensitive ? 'LOWER('.$expression.')' : $expression;
        $right = $caseInsensitive ? 'LOWER('.$placeholder.')' : $placeholder;

        return match ($operator) {
            SmartCollectionOperator::Equals => $left.' = '.$right,
            SmartCollectionOperator::NotEquals => $left.' <> '.$right,
            SmartCollectionOperator::GreaterThanOrEqual => $left.' >= '.$right,
            SmartCollectionOperator::LessThanOrEqual => $left.' <= '.$right,
            default => throw new \LogicException('Unsupported normalized scalar operator.'),
        };
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function compileText(
        string $column,
        SmartCollectionOperator $operator,
        mixed $value,
        array &$parameters,
        int &$counter,
    ): string {
        if ($operator === SmartCollectionOperator::Contains) {
            $placeholder = $this->parameter($value, $parameters, $counter);

            return sprintf(
                "POSITION(LOWER(%s) IN LOWER(COALESCE(%s, ''))) > 0",
                $placeholder,
                $column,
            );
        }

        return $this->compileScalar(
            $column,
            $operator,
            $value,
            $parameters,
            $counter,
            true,
        );
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function compileTag(
        string $alias,
        string $value,
        array &$parameters,
        int &$counter,
    ): string {
        $slug = $this->parameter($value, $parameters, $counter);
        $name = $this->parameter($value, $parameters, $counter);

        return 'EXISTS (
            SELECT 1
            FROM media_tags smart_mt
            JOIN tags smart_t ON smart_t.id = smart_mt.tag_id
            WHERE smart_mt.media_id = '.$alias.'.id
              AND (
                  smart_t.slug = '.$slug.'
                  OR LOWER(smart_t.name) = LOWER('.$name.')
              )
        )';
    }

    private function compileOrientation(
        string $alias,
        SmartCollectionOperator $operator,
        mixed $value,
    ): string {
        $values = is_array($value) ? $value : [$value];
        $parts = array_map(
            static fn (string $orientation): string => match ($orientation) {
                'portrait' => $alias.'.width < '.$alias.'.height',
                'landscape' => $alias.'.width > '.$alias.'.height',
                'square' => $alias.'.width = '.$alias.'.height',
                default => throw new \LogicException('Unsupported normalized orientation.'),
            },
            $values,
        );

        $sql = '('.implode(' OR ', array_map(
            static fn (string $part): string => '('.$part.')',
            $parts,
        )).')';

        return $operator === SmartCollectionOperator::NotEquals
            ? 'NOT '.$sql
            : $sql;
    }

    private function textColumn(SmartCollectionField $field): string
    {
        return match ($field) {
            SmartCollectionField::Creator => 'creator',
            SmartCollectionField::CameraMake => 'camera_make',
            SmartCollectionField::CameraModel => 'camera_model',
            SmartCollectionField::Lens => 'lens',
            SmartCollectionField::LocationName => 'location_name',
            default => throw new \LogicException('Field is not a text column.'),
        };
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function parameter(
        mixed $value,
        array &$parameters,
        int &$counter,
    ): string {
        ++$counter;
        $name = 'smart_'.$counter;
        $parameters[$name] = $value;

        return ':'.$name;
    }
}
