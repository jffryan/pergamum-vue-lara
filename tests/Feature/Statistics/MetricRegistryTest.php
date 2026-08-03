<?php

namespace Tests\Feature\Statistics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricRegistry;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The registry's own contract: what gets returned, what 422s, what degrades.
 */
class MetricRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_omitting_metrics_returns_every_key_the_scope_supports(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/statistics')->assertOk();

        $supported = app(MetricRegistry::class)->keysFor(new Scope(Scope::USER, 1));
        $this->assertEqualsCanonicalizing($supported, array_keys($response->json('metrics')));
    }

    public function test_requesting_a_subset_returns_only_those_keys(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/statistics?metrics=totalBooks,readsByYear')->assertOk();

        $this->assertSame(['totalBooks', 'readsByYear'], array_keys($response->json('metrics')));
    }

    public function test_dependencies_are_computed_without_appearing_in_the_response(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/statistics?metrics=percentageOfBooksRead')->assertOk();

        $this->assertSame(['percentageOfBooksRead'], array_keys($response->json('metrics')));
        $this->assertEquals(0, $response->json('metrics.percentageOfBooksRead'));
    }

    public function test_a_dependency_is_computed_exactly_once_per_request(): void
    {
        $scope = new Scope(Scope::USER, $this->actingAsUser()->user_id);

        $counted = new CountingMetric;
        $registry = new MetricRegistry([$counted, new DependsOnCounting('first'), new DependsOnCounting('second')]);

        $registry->compute($scope, ['first', 'second']);

        $this->assertSame(1, $counted->calls);
    }

    public function test_unknown_metric_key_is_rejected(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/statistics?metrics=totalBooks,notAMetric')
            ->assertStatus(422)
            ->assertJsonValidationErrors('metrics.1');
    }

    public function test_unknown_scope_is_not_found(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/statistics/planets')->assertNotFound();
    }

    public function test_statistics_require_authentication(): void
    {
        $this->getJson('/api/statistics')->assertUnauthorized();
    }

    public function test_a_failing_metric_is_reported_rather_than_failing_the_response(): void
    {
        $this->actingAsUser();

        app(MetricRegistry::class)->register(new ExplodingMetric);

        $response = $this->getJson('/api/statistics?metrics=totalBooks,explodes')->assertOk();

        $this->assertSame(['totalBooks'], array_keys($response->json('metrics')));
        $this->assertSame(['explodes'], $response->json('meta.failed'));
    }
}

class CountingMetric extends AbstractMetric
{
    public int $calls = 0;

    public function key(): string
    {
        return 'counted';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        $this->calls++;

        return $this->calls;
    }
}

class DependsOnCounting extends AbstractMetric
{
    public function __construct(private string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    public function dependsOn(): array
    {
        return ['counted'];
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return (int) $results->get('counted');
    }
}

class ExplodingMetric extends AbstractMetric
{
    public function key(): string
    {
        return 'explodes';
    }

    public function compute(Scope $scope, MetricResults $results): never
    {
        throw new RuntimeException('An orphaned row, say.');
    }
}
