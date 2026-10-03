<?php

declare(strict_types=1);

namespace App\Enums;

enum AggregateDimension: string
{
    case EventName = 'event_name';
    case Screen = 'screen';
    case Version = 'version';
    case Platform = 'platform';
    case OperatingSystem = 'os';
}
