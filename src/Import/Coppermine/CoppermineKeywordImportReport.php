<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

final readonly class CoppermineKeywordImportReport
{
    /**
     * @param list<string> $unmappedPictureIds
     * @param list<string> $unmappedAlbumIds
     */
    public function __construct(
        public int $createdTags,
        public int $createdLinks,
        public int $linkedAlbumMembershipsImported,
        public array $unmappedPictureIds,
        public array $unmappedAlbumIds,
        public string $separator,
    ) {
    }
}
