<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

final readonly class CoppermineInteractionImportReport
{
    /** @param list<string> $warnings */
    public function __construct(
        public int $commentsImported,
        public int $favoritesImported,
        public int $ratingsImported,
        public int $ratingAggregatesPreserved,
        public int $aggregateVotesWithoutRecoverableIndividualRatings,
        public array $warnings,
    ) {
    }
}
