<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * Audio minutes listened per calendar year.
 *
 * `versions.audio_runtime` is stored in whole minutes; formatting that as
 * hours is a display concern, so the metric hands back minutes.
 *
 * The mirror image of {@see PagesReadByYear}, and equally partial on its own.
 */
class AudioRuntimeByYear extends AbstractMetric
{
    public function key(): string
    {
        return 'audioRuntimeByYear';
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        $reads = ReadInstanceQuery::forScope($scope)->joinVersions();

        $reads->query()->whereNotNull('versions.audio_runtime');

        return $reads->groupedByYear('SUM(versions.audio_runtime)');
    }
}
