<?php

namespace App\Statistics;

use App\Statistics\Contracts\Metric;

/**
 * Defaults for the common case: a user-scoped, measured, on-shelf-agnostic
 * metric with no dependencies. Concrete metrics override only what makes
 * them unusual, so the declarations that *are* present carry information.
 */
abstract class AbstractMetric implements Metric
{
    /**
     * Scope types this metric knows how to build a query for.
     *
     * @var string[]
     */
    protected array $scopes = [Scope::USER];

    public function supports(Scope $scope): bool
    {
        return in_array($scope->type, $this->scopes, true);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function isCatalogWide(): bool
    {
        return false;
    }

    public function isShelfScoped(): bool
    {
        return false;
    }

    public function isEstimated(): bool
    {
        return false;
    }

    public function estimationMeta(): array
    {
        return [];
    }
}
