<?php

namespace Tests\Feature\Statistics;

use App\Models\Book;
use App\Models\Location;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `location` statistics scope. One scope serves shelf, bookcase and room
 * alike — the subtree is just wider — which is why the bookcase tests below
 * are also the room tests in miniature.
 */
class LocationMetricsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Location, 1: Location, 2: Location} bookcase, shelf one, shelf two */
    private function bookcaseWithTwoShelves(): array
    {
        $bookcase = Location::factory()->kind('bookcase')->create(['code' => 'O1', 'slug' => 'o1']);
        $one = Location::factory()->childOf($bookcase)->create(['code' => 'O1S1', 'slug' => 'o1s1']);
        $two = Location::factory()->childOf($bookcase)->create(['code' => 'O1S2', 'slug' => 'o1s2']);

        return [$bookcase, $one, $two];
    }

    public function test_scope_resolves_by_slug_and_reports_over_the_subtree(): void
    {
        $user = $this->actingAsUser();
        [, $shelfOne, $shelfTwo] = $this->bookcaseWithTwoShelves();

        $read = Version::factory()->create(['location_id' => $shelfOne->location_id, 'page_count' => 300]);
        Version::factory()->create(['location_id' => $shelfTwo->location_id, 'page_count' => 200]);
        ReadInstance::factory()->create([
            'user_id' => $user->user_id,
            'version_id' => $read->version_id,
            'book_id' => $read->book_id,
        ]);

        $response = $this->getJson('/api/statistics/location/o1')->assertOk();

        $this->assertSame('location', $response->json('scope.type'));
        $this->assertSame(2, $response->json('metrics.totalItems'));
        $this->assertSame(500, $response->json('metrics.totalPages'));
        $this->assertSame(1, $response->json('metrics.completedCount'));
        $this->assertSame(50, $response->json('metrics.completedPercent'));
    }

    public function test_an_empty_shelf_reports_zeroes(): void
    {
        $this->actingAsUser();
        [, $shelfOne] = $this->bookcaseWithTwoShelves();

        $response = $this->getJson("/api/statistics/location/{$shelfOne->slug}")->assertOk();

        $this->assertSame(0, $response->json('metrics.totalItems'));
        $this->assertSame(0, $response->json('metrics.completedPercent'));
    }

    public function test_reads_only_count_for_the_requesting_user(): void
    {
        $this->actingAsUser();
        [, $shelfOne] = $this->bookcaseWithTwoShelves();

        $version = Version::factory()->create(['location_id' => $shelfOne->location_id]);
        ReadInstance::factory()->create([
            'version_id' => $version->version_id,
            'book_id' => $version->book_id,
            // someone else's read of this shelved book
        ]);

        $response = $this->getJson('/api/statistics/location/o1s1')->assertOk();

        $this->assertSame(1, $response->json('metrics.totalItems'));
        $this->assertSame(0, $response->json('metrics.completedCount'));
    }

    public function test_an_unknown_location_is_a_404(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/statistics/location/nowhere')->assertNotFound();
    }
}
