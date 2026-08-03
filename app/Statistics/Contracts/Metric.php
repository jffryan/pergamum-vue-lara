<?php

namespace App\Statistics\Contracts;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * One number or one series, computed on demand for a given scope.
 *
 * The three `is*()` declarations exist so the response can describe its own
 * caveats as data rather than prose. Each covers a different axis:
 *
 * - `isCatalogWide()`  — *whose* data: the metric ignores user scoping.
 * - `isShelfScoped()`  — *which* copies: fully-discarded books are excluded.
 * - `isEstimated()`    — *how sure*: the number is derived, not measured.
 *
 * They are orthogonal; a metric can sit anywhere on each independently.
 * See {@see AbstractMetric} for the defaults.
 */
interface Metric
{
    /**
     * The camelCase key this metric is requested and returned under.
     */
    public function key(): string;

    public function supports(Scope $scope): bool;

    /**
     * Keys of metrics whose values this one needs. The registry computes
     * them first and passes them in via {@see MetricResults}, exactly once
     * per request even when several metrics depend on the same one.
     *
     * @return string[]
     */
    public function dependsOn(): array;

    public function isCatalogWide(): bool;

    public function isShelfScoped(): bool;

    public function isEstimated(): bool;

    /**
     * How the number was derived, echoed under `meta.estimated` so a widget
     * can render the caveat from data. Empty unless `isEstimated()`.
     */
    public function estimationMeta(): array;

    public function compute(Scope $scope, MetricResults $results): mixed;
}
