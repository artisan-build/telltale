<?php

declare(strict_types=1);

namespace App\Domain\Storage;

use App\Models\ErrorGroup;
use App\Models\ErrorGroupInstall;
use App\Models\Install;
use App\Models\TrackedApp;
use Illuminate\Support\Facades\DB;

final class InstallDataDeleter
{
    public function delete(TrackedApp $app, string $installUuid): int
    {
        return DB::transaction(function () use ($app, $installUuid): int {
            TrackedApp::query()->whereKey($app->id)->lockForUpdate()->firstOrFail();
            $install = Install::query()
                ->where('app_id', $app->id)
                ->where('install_uuid', $installUuid)
                ->lockForUpdate()
                ->firstOrFail();
            $rawEventCount = $install->events()->count();

            $memberships = ErrorGroupInstall::query()->where('install_id', $install->id)->get();

            foreach ($memberships as $membership) {
                $groupId = $membership->error_group_id;
                $membership->delete();
                $this->rebuildErrorGroup($groupId);
            }

            $install->delete();

            return $rawEventCount;
        });
    }

    private function rebuildErrorGroup(int $groupId): void
    {
        $group = ErrorGroup::query()->findOrFail($groupId);
        $remaining = ErrorGroupInstall::query()->where('error_group_id', $groupId)->get();

        if ($remaining->isEmpty()) {
            $group->delete();

            return;
        }

        $first = $remaining->sortBy('first_seen_at')->firstOrFail();
        $last = $remaining->sortByDesc('last_seen_at')->firstOrFail();
        $group->forceFill([
            'first_seen_at' => $first->first_seen_at,
            'last_seen_at' => $last->last_seen_at,
            'first_version' => $first->first_version,
            'last_version' => $last->last_version,
            'total_occurrences' => $remaining->sum('occurrence_count'),
            'installs_affected' => $remaining->count(),
            'sample_error' => $first->sample_error,
            'sample_context' => $first->sample_context,
        ])->save();
    }
}
