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
    case Locale = 'locale';
    case ScreenVersion = 'screen_version';
    case ScreenPlatform = 'screen_platform';
    case ScreenOperatingSystem = 'screen_os';
    case ScreenLocale = 'screen_locale';
}
