<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * How far through a list the user is, 0–100.
 *
 * Rounded to whole percent, matching what the view used to compute.
 */
class CompletedPercent extends AbstractMetric
{
    protected array $scopes = [Scope::LIST, Scope::LOCATION];

    public function key(): string
    {
        return 'completedPercent';
    }

    public function dependsOn(): array
    {
        return ['totalItems', 'completedCount'];
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        $total = (int) $results->get('totalItems');

        if ($total <= 0) {
            return 0;
        }

        return (int) round(((int) $results->get('completedCount') / $total) * 100);
    }
}
