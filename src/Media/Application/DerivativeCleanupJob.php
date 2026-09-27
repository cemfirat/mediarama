<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;
use Mediarama\Media\Domain\StorageObjectId;
use Symfony\Component\Uid\Uuid;

final readonly class DerivativeCleanupJob
{
    public function __construct(
        public int $id,
        public StorageObjectId $storage,
        public string $reason,
        public ?Uuid $mediaId,
        public ?string $kind,
        public ?string $profile,
        public ?int $processingVersion,
        public DateTimeImmutable $createdAt,
    ) {
        if ($id < 1 || $reason === '') {
            throw new \InvalidArgumentException('Invalid derivative cleanup job.');
        }
    }
}
