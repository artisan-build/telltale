<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\DailyActiveInstall;
use App\Models\DailyNewInstall;
use App\Models\TrackedApp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class RetentionQuery
{
    /**
     * @return list<array{cohort_week: string, cohort_size: int, weeks: list<array{week: int, returned: int, retention_rate: float}>}>
     */
    public function cohorts(
        TrackedApp $app,
        CarbonImmutable $fromWeek,
        CarbonImmutable $throughWeek,
        int $weeks,
    ): array {
        if ($weeks < 0) {
            return [];
        }

        $from = $fromWeek->startOfWeek(CarbonInterface::MONDAY);
        $through = $throughWeek->startOfWeek(CarbonInterface::MONDAY);
        $installs = DailyNewInstall::query()
            ->where('app_id', $app->id)
            ->whereBetween('first_seen_on', [$from->toDateString(), $through->endOfWeek()->toDateString()])
            ->get();
        $installIds = $installs->pluck('install_id')->all();
        $activeWeeks = [];

        if ($installIds !== []) {
            $activeRows = DailyActiveInstall::query()
                ->where('app_id', $app->id)
                ->whereIn('install_id', $installIds)
                ->where('active_date', '<=', $through->addWeeks($weeks)->endOfWeek()->toDateString())
                ->get();

            foreach ($activeRows as $row) {
                $week = CarbonImmutable::parse($row->active_date)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
                $activeWeeks[$row->install_id][$week] = true;
            }
        }

        $cohorts = [];

        foreach ($installs as $install) {
            $week = CarbonImmutable::parse($install->first_seen_on)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
            $cohorts[$week][] = $install->install_id;
        }

        ksort($cohorts);
        $result = [];

        foreach ($cohorts as $cohortWeek => $cohortInstallIds) {
            $size = count($cohortInstallIds);
            $periods = [];

            for ($period = 0; $period <= $weeks; $period++) {
                $activeWeek = CarbonImmutable::parse($cohortWeek)->addWeeks($period)->toDateString();
                $returned = 0;

                foreach ($cohortInstallIds as $installId) {
                    if (isset($activeWeeks[$installId][$activeWeek])) {
                        $returned++;
                    }
                }

                $periods[] = [
                    'week' => $period,
                    'returned' => $returned,
                    'retention_rate' => $size === 0 ? 0.0 : round(($returned / $size) * 100, 2),
                ];
            }

            $result[] = [
                'cohort_week' => $cohortWeek,
                'cohort_size' => $size,
                'weeks' => $periods,
            ];
        }

        return $result;
    }
}
