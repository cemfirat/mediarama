<?php

declare(strict_types=1);

namespace Mediarama\Http\Support;

use DateTimeImmutable;
use Mediarama\Media\Application\LibraryMediaSearchCriteria;
use Symfony\Component\HttpFoundation\InputBag;

final class LibraryMediaSearchCriteriaFactory
{
    public function fromInput(InputBag $input): LibraryMediaSearchCriteria
    {
        return new LibraryMediaSearchCriteria(
            text: $input->getString('q') ?: null,
            creator: $input->getString('creator') ?: null,
            cameraMake: $input->getString('camera_make') ?: null,
            cameraModel: $input->getString('camera_model') ?: null,
            lens: $input->getString('lens') ?: null,
            mediaType: $this->nullableString($input, 'media_type'),
            locationName: $this->nullableString($input, 'location_name'),
            tag: $this->nullableString($input, 'tag'),
            minimumRating: $this->rating($input, 'rating_min'),
            orientation: $this->nullableString($input, 'orientation'),
            minimumIso: $this->positiveInteger($input, 'iso_min'),
            maximumIso: $this->positiveInteger($input, 'iso_max'),
            capturedFrom: $this->date($input, 'captured_from'),
            capturedUntil: $this->date($input, 'captured_until'),
            hasLocation: $this->boolean($input, 'has_location'),
            limit: min(max($input->getInt('limit', 50), 1), 200),
            offset: max($input->getInt('offset', 0), 0),
        );
    }

    private function nullableString(InputBag $input, string $key): ?string
    {
        if (!$input->has($key)) {
            return null;
        }

        $value = trim($input->getString($key));

        return $value === '' ? null : $value;
    }

    private function rating(InputBag $input, string $key): ?float
    {
        if (!$input->has($key)) {
            return null;
        }

        $value = trim($input->getString($key));
        if ($value === '' || !is_numeric($value)) {
            throw new \InvalidArgumentException('Invalid rating.');
        }

        $rating = (float) $value;
        if ($rating < 1.0 || $rating > 5.0) {
            throw new \InvalidArgumentException('Invalid rating.');
        }

        return $rating;
    }

    private function positiveInteger(InputBag $input, string $key): ?int
    {
        if (!$input->has($key)) {
            return null;
        }

        $value = trim($input->getString($key));
        if ($value === '' || !ctype_digit($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException('Invalid positive integer.');
        }

        return (int) $value;
    }

    private function boolean(InputBag $input, string $key): ?bool
    {
        if (!$input->has($key)) {
            return null;
        }

        return match (strtolower(trim($input->getString($key)))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new \InvalidArgumentException('Invalid boolean.'),
        };
    }

    private function date(InputBag $input, string $key): ?DateTimeImmutable
    {
        if (!$input->has($key)) {
            return null;
        }

        $value = trim($input->getString($key));
        if ($value === '') {
            throw new \InvalidArgumentException('Invalid date.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        } else {
            $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        return $date;
    }
}
