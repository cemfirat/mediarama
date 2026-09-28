<?php

declare(strict_types=1);

namespace Mediarama\Collection\Application;

use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Media\Application\LibraryMediaSearchCriteria;

final class LibraryFilterSmartCollectionRuleFactory
{
    public function create(LibraryMediaSearchCriteria $criteria): SmartCollectionRule
    {
        if (
            $criteria->text !== null
            || $criteria->minimumIso !== null
            || $criteria->maximumIso !== null
            || $criteria->hasLocation !== null
        ) {
            throw new \InvalidArgumentException(
                'This library filter contains criteria that cannot be represented safely by Smart Collection rule V1.',
            );
        }

        $rules = [];

        foreach ([
            'creator' => $criteria->creator,
            'camera_make' => $criteria->cameraMake,
            'camera_model' => $criteria->cameraModel,
            'lens' => $criteria->lens,
        ] as $field => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $rules[] = [
                'field' => $field,
                'operator' => 'contains',
                'value' => $value,
            ];
        }

        if ($criteria->capturedFrom !== null && $criteria->capturedUntil !== null) {
            $rules[] = [
                'field' => 'captured_at',
                'operator' => 'between',
                'value' => [
                    $criteria->capturedFrom->format(DATE_ATOM),
                    $criteria->capturedUntil->format(DATE_ATOM),
                ],
            ];
        } elseif ($criteria->capturedFrom !== null) {
            $rules[] = [
                'field' => 'captured_at',
                'operator' => 'gte',
                'value' => $criteria->capturedFrom->format(DATE_ATOM),
            ];
        } elseif ($criteria->capturedUntil !== null) {
            $rules[] = [
                'field' => 'captured_at',
                'operator' => 'lte',
                'value' => $criteria->capturedUntil->format(DATE_ATOM),
            ];
        }

        if ($rules === []) {
            throw new \InvalidArgumentException(
                'Add at least one compatible library filter before saving a Smart Collection.',
            );
        }

        return SmartCollectionRule::fromArray([
            'version' => SmartCollectionRule::VERSION,
            'op' => 'and',
            'rules' => $rules,
        ]);
    }
}
