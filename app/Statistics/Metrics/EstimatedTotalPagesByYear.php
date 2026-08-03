<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * Pages read per year with audio folded in at a stated conversion rate.
 *
 * `pagesReadByYear` and `audioRuntimeByYear` are each measured and each
 * partial — neither answers "how much did I read" for someone who does both.
 * This one does, at the cost of being part-guess, which is why it declares
 * `isEstimated()` and ships the factor it used in `meta.estimated` instead of
 * hiding it in SQL.
 *
 * Normalising audio *into* pages rather than the reverse is deliberate: the
 * dashboard already shows pages per year, so the combined number extends a
 * unit people read fluently rather than introducing a second one.
 */
class EstimatedTotalPagesByYear extends AbstractMetric
{
    public function key(): string
    {
        return 'estimatedTotalPagesByYear';
    }

    public function dependsOn(): array
    {
        return ['pagesReadByYear', 'audioRuntimeByYear'];
    }

    public function isEstimated(): bool
    {
        return true;
    }

    public function estimationMeta(): array
    {
        return [
            'pagesPerAudioMinute' => $this->pagesPerAudioMinute(),
            'from' => $this->dependsOn(),
            // Which of the inputs the factor was applied to, so a widget can
            // name the guessed portion without knowing what audio is.
            'converted' => 'audioRuntimeByYear',
        ];
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        $factor = $this->pagesPerAudioMinute();
        $totals = [];

        foreach ($results->get('pagesReadByYear') as $row) {
            $totals[$row['year']] = ($totals[$row['year']] ?? 0) + $row['total'];
        }

        foreach ($results->get('audioRuntimeByYear') as $row) {
            $totals[$row['year']] = ($totals[$row['year']] ?? 0) + (int) round($row['total'] * $factor);
        }

        krsort($totals);

        return array_map(
            fn ($year, $total) => ['year' => (int) $year, 'total' => (int) $total],
            array_keys($totals),
            $totals
        );
    }

    private function pagesPerAudioMinute(): float
    {
        return (float) config('statistics.estimates.pagesPerAudioMinute');
    }
}
