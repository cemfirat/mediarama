<?php

declare(strict_types=1);

namespace Mediarama\Collection\Domain;

enum SmartCollectionField: string
{
    case MediaType = 'media_type';
    case CapturedAt = 'captured_at';
    case Creator = 'creator';
    case CameraMake = 'camera_make';
    case CameraModel = 'camera_model';
    case Lens = 'lens';
    case LocationName = 'location_name';
    case Tag = 'tag';
    case RatingAverage = 'rating_average';
    case Orientation = 'orientation';
}
