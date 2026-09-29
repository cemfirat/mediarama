<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

final readonly class CoppermineAclImportReport
{
    /** @param list<string> $unmappedPrincipals
     *  @param list<string> $passwordResetAlbums
     */
    public function __construct(
        public int $processedAlbums,
        public int $createdAccessRules,
        public int $createdCategoryCreationRules,
        public array $unmappedPrincipals,
        public array $passwordResetAlbums,
    ) {
    }
}
