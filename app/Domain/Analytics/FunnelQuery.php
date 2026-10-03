<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\StoredEvent;
use App\Models\TrackedApp;
use Carbon\CarbonImmutable;

final class FunnelQuery
{
    /**
     * @param  non-empty-list<string>  $steps
     * @return list<array{step: string, reached: int, conversion_rate: float, drop_off: int}>
     */
    public function run(
        TrackedApp $app,
        array $steps,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $windowMinutes,
    ): array {
        if ($windowMinutes < 1) {
            return [];
        }

        $counts = array_fill(0, count($steps), 0);
        $eventsByInstall = StoredEvent::query()
            ->where('app_id', $app->id)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->orderBy('install_id')
            ->oldest('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('install_id');

        foreach ($eventsByInstall as $events) {
            $reached = array_fill(0, count($steps), false);
            $nextStep = 0;
            $startedAt = null;

            foreach ($events as $event) {
                if ($startedAt !== null && $event->occurred_at->greaterThan($startedAt->addMinutes($windowMinutes))) {
                    $nextStep = 0;
                    $startedAt = null;
                }

                if ($event->name === $steps[0]) {
                    $reached[0] = true;
                    $nextStep = 1;
                    $startedAt = $event->occurred_at;

                    if (count($steps) === 1) {
                        break;
                    }

                    continue;
                }

                if ($startedAt !== null && isset($steps[$nextStep]) && $event->name === $steps[$nextStep]) {
                    $reached[$nextStep] = true;
                    $nextStep++;

                    if ($nextStep === count($steps)) {
                        break;
                    }
                }
            }

            foreach ($reached as $index => $didReach) {
                if ($didReach) {
                    $counts[$index]++;
                }
            }
        }

        $entrants = $counts[0];
        $result = [];

        foreach ($steps as $index => $step) {
            $result[] = [
                'step' => $step,
                'reached' => $counts[$index],
                'conversion_rate' => $entrants === 0 ? 0.0 : round(($counts[$index] / $entrants) * 100, 2),
                'drop_off' => $index === 0 ? 0 : $counts[$index - 1] - $counts[$index],
            ];
        }

        return $result;
    }
}
