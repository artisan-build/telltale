<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SetupInstructions;
use Illuminate\Contracts\View\View;

final readonly class SetupController
{
    public function __invoke(SetupInstructions $instructions): View
    {
        return view('setup', [
            'steps' => $instructions->steps(),
            'automationBoundary' => $instructions->automationBoundary(),
            'v1LimitSummary' => $instructions->v1LimitSummary(),
        ]);
    }
}
