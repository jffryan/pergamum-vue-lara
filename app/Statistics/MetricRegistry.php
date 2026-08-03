<?php

namespace App\Statistics;

use App\Statistics\Contracts\Metric;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Key -> metric, plus the machinery that turns a list of requested keys into
 * a response: pull in dependencies, order them, run each exactly once, and
 * describe what came back.
 */
class MetricRegistry
{
    /** @var array<string, Metric> */
    private array $metrics = [];

    /**
     * @param  iterable<Metric>  $metrics
     */
    public function __construct(iterable $metrics = [])
    {
        foreach ($metrics as $metric) {
            $this->register($metric);
        }
    }

    public function register(Metric $metric): void
    {
        $this->metrics[$metric->key()] = $metric;
    }

    public function has(string $key): bool
    {
        return isset($this->metrics[$key]);
    }

    public function get(string $key): Metric
    {
        if (! $this->has($key)) {
            throw new RuntimeException("Unknown metric [{$key}].");
        }

        return $this->metrics[$key];
    }

    /**
     * Every metric key, regardless of scope.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->metrics);
    }

    /**
     * The keys a given scope can actually serve — the validation whitelist,
     * and the default metric set when the request omits `metrics`.
     *
     * @return string[]
     */
    public function keysFor(Scope $scope): array
    {
        return array_values(array_keys(array_filter(
            $this->metrics,
            fn (Metric $metric) => $metric->supports($scope)
        )));
    }

    /**
     * @param  string[]|null  $keys  null requests everything the scope supports
     * @return array{metrics: array<string, mixed>, meta: array}
     */
    public function compute(Scope $scope, ?array $keys = null): array
    {
        $requested = array_values(array_unique($keys ?? $this->keysFor($scope)));
        $results = new MetricResults;
        $failed = [];

        foreach ($this->resolveOrder($requested) as $key) {
            $metric = $this->get($key);

            try {
                if (! $metric->supports($scope)) {
                    throw new RuntimeException(
                        "Metric [{$key}] does not support the [{$scope->type}] scope."
                    );
                }

                $results->put($key, $metric->compute($scope, $results));
            } catch (Throwable $e) {
                // One orphaned row degrades a single card rather than 500-ing
                // the whole surface.
                Log::warning("Statistics metric [{$key}] failed for scope [{$scope->key()}]: {$e->getMessage()}", [
                    'exception' => $e,
                ]);
                $failed[] = $key;
            }
        }

        $returned = array_values(array_filter($requested, fn ($key) => $results->has($key)));

        return [
            'metrics' => $this->orderedValues($results, $returned),
            'meta' => $this->meta($returned, array_values(array_intersect($requested, $failed))),
        ];
    }

    /**
     * Requested keys plus their transitive dependencies, dependencies first.
     *
     * @param  string[]  $requested
     * @return string[]
     */
    private function resolveOrder(array $requested): array
    {
        $ordered = [];
        $state = [];

        $visit = function (string $key, array $trail) use (&$visit, &$ordered, &$state) {
            if (($state[$key] ?? null) === 'done') {
                return;
            }

            if (($state[$key] ?? null) === 'visiting') {
                $cycle = implode(' -> ', [...$trail, $key]);
                throw new RuntimeException("Circular metric dependency: {$cycle}.");
            }

            $state[$key] = 'visiting';

            foreach ($this->get($key)->dependsOn() as $dependency) {
                $visit($dependency, [...$trail, $key]);
            }

            $state[$key] = 'done';
            $ordered[] = $key;
        };

        foreach ($requested as $key) {
            $visit($key, []);
        }

        return $ordered;
    }

    /**
     * @param  string[]  $keys
     */
    private function orderedValues(MetricResults $results, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $results->get($key);
        }

        return $values;
    }

    /**
     * The response's self-description. Each list is populated from a
     * declaration on the metric class, so a new metric cannot silently omit
     * itself from a caveat it belongs in.
     *
     * @param  string[]  $returned
     * @param  string[]  $failed
     */
    private function meta(array $returned, array $failed): array
    {
        $estimated = [];

        foreach ($returned as $key) {
            if ($this->get($key)->isEstimated()) {
                $estimated[$key] = $this->get($key)->estimationMeta();
            }
        }

        return [
            'catalogWide' => $this->filterByFlag($returned, fn (Metric $m) => $m->isCatalogWide()),
            'shelfScoped' => $this->filterByFlag($returned, fn (Metric $m) => $m->isShelfScoped()),
            'estimated' => (object) $estimated,
            'failed' => $failed,
        ];
    }

    /**
     * @param  string[]  $keys
     * @return string[]
     */
    private function filterByFlag(array $keys, callable $flag): array
    {
        return array_values(array_filter($keys, fn ($key) => $flag($this->get($key))));
    }
}
