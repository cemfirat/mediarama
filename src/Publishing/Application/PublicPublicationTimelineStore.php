<?php

declare(strict_types=1);

namespace Mediarama\Publishing\Application;

use DateTimeImmutable;
use Mediarama\Publishing\Domain\PublicationOrigin;
use Mediarama\Publishing\Domain\PublicPublicationTimeline;
use Symfony\Component\Uid\Uuid;

interface PublicPublicationTimelineStore
{
    public function media(Uuid $mediaId): PublicPublicationTimeline;

    public function collection(Uuid $collectionId): PublicPublicationTimeline;

    public function recordMediaFirstPublication(
        Uuid $mediaId,
        DateTimeImmutable $publishedAt,
        PublicationOrigin $origin = PublicationOrigin::Editorial,
        ?string $source = null,
    ): PublicPublicationTimeline;

    public function recordCollectionFirstPublication(
        Uuid $collectionId,
        DateTimeImmutable $publishedAt,
        PublicationOrigin $origin = PublicationOrigin::Editorial,
        ?string $source = null,
    ): PublicPublicationTimeline;

    public function touchMediaPublicContent(
        Uuid $mediaId,
        DateTimeImmutable $changedAt,
    ): PublicPublicationTimeline;

    public function touchCollectionPublicContent(
        Uuid $collectionId,
        DateTimeImmutable $changedAt,
    ): PublicPublicationTimeline;
}
