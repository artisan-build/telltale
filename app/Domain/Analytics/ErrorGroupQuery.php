<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\ErrorGroup;
use App\Models\TrackedApp;

final class ErrorGroupQuery
{
    /** @return list<ErrorGroup> */
    public function newSinceVersion(TrackedApp $app, string $version): array
    {
        return ErrorGroup::query()
            ->where('app_id', $app->id)
            ->get()
            ->filter(fn (ErrorGroup $group): bool => $group->first_version !== '<unknown>'
                && version_compare($group->first_version, $version, '>'))
            ->values()
            ->all();
    }
}
