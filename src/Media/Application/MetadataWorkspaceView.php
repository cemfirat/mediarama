<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class MetadataWorkspaceView
{
    /**
     * @param list<array{key:string,label:string,value:?string,provenance:?string,sensitive:bool}> $currentFields
     * @param array<string,array{label:string,tags:list<array{name:string,value:string,sensitive:bool}>}> $sourceGroups
     */
    public function __construct(
        public Uuid $id,
        public string $originalFilename,
        public string $mimeType,
        public string $mediaType,
        public int $byteSize,
        public ?int $width,
        public ?int $height,
        public ?int $durationMs,
        public ?string $title,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $currentFields,
        public array $sourceGroups,
    ) {
    }
}
