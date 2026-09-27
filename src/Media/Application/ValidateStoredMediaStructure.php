<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;

interface ValidateStoredMediaStructure
{
    /**
     * @throws MediaValidationRejected when the uploaded bytes are structurally invalid
     * @throws MediaValidationUnavailable when validation infrastructure cannot decide safely
     */
    public function __invoke(StorageObjectId $object, MediaType $mediaType): void;
}
