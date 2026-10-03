<?php

declare(strict_types=1);

namespace App\Authorization;

enum TelltaleAbility: string
{
    case Read = 'telltale.read';
}
