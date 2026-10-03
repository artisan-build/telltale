<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

enum EventType: string
{
    case Screen = 'screen';
    case Event = 'event';
    case Error = 'error';
    case SessionStart = 'session_start';
    case SessionEnd = 'session_end';
    case Context = 'context';
}
