<?php

namespace Tests\Feature\Locations;

use App\Models\Book;
use App\Models\Location;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationsCrudTest extends TestCase
{
    use RefreshDatabase;

    /** A room > bookcase > shelf chain, returned shelf-first. */
    private function chain(): array
    {
        $room = Location::factory()->kind('room')->create(['code' => 'O', 'slug' => 'o']);
        $bookcase = Location::factory()->kind('bookcase')->childOf($room)->create(['code' => 'O1', 'slug' => 'o1']);
        $shelf = Location::factory()->childOf($bookcase)->create(['code' => 'O1S1', 'slug' => 'o1s1']);

        return [$shelf, $bookcase, $room];
    }

    public function test_index_returns_every_location_with_version_counts(): void
    {
        $this->actingAsUser();
        [$shelf] = $this->chain();
        Version::factory()->count(2)->create(['location_id' => $shelf->location_id]);

        $response = $this->getJson('/api/locations');

        $response->assertOk();
        $response->assertJsonCount(3);
        $this->assertSame(2, collect($response->json())->firstWhere('code', 'O1S1')['versions_count']);
    }

    public function test_store_creates_a_location_under_a_parent(): void
    {
        $this->actingAsUser();
        [, $bookcase] = $this->chain();

        $response = $this->postJson('/api/locations', [
            'code' => 'O1S2',
            'kind' => 'shelf',
            'parent_id' => $bookcase->location_id,
            'ordinal' => 2,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('slug', 'o1s2');
        $this->assertDatabaseHas('locations', ['code' => 'O1S2', 'parent_id' => $bookcase->location_id]);
    }

    public function test_store_with_a_taken_code_is_a_409_carrying_the_conflict(): void
    {
        $this->actingAsUser();
        [$shelf] = $this->chain();

        // Lowercased on purpose — codes collide at the slug, so 'o1s1' and
        // 'O1S1' are one place.
        $response = $this->postJson('/api/locations', ['code' => 'o1s1', 'kind' => 'shelf']);

        $response->assertStatus(409);
        $response->assertJsonPath('reason_code', 'location_code_taken');
        $this->assertSame($shelf->location_id, $response->json('conflict.location_id'));
    }

    public function test_show_routes_by_slug_and_carries_ancestors_and_children(): void
    {
        $this->actingAsUser();
        [$shelf, $bookcase, $room] = $this->chain();
        Version::factory()->create(['location_id' => $shelf->location_id]);

        $response = $this->getJson('/api/locations/o1');

        $response->assertOk();
        $response->assertJsonStructure(['location', 'ancestors', 'children', 'subtree_versions_count']);
        $this->assertSame($room->location_id, $response->json('ancestors.0.location_id'));
        $this->assertSame($shelf->location_id, $response->json('children.0.location_id'));
        $this->assertSame(1, $response->json('subtree_versions_count'));
        $this->assertSame($bookcase->location_id, $response->json('location.location_id'));
    }

    /**
     * The bug this pins: a bookcase shelves nothing directly — its copies sit
     * on its shelves — so a child chip rendering the direct count read (0).
     * Children carry a subtree rollup alongside it.
     */
    public function test_children_carry_subtree_counts_not_just_direct_ones(): void
    {
        $this->actingAsUser();
        [$shelf] = $this->chain();
        Version::factory()->count(3)->create(['location_id' => $shelf->location_id]);

        $response = $this->getJson('/api/locations/o');

        $response->assertOk();
        $this->assertSame(0, $response->json('children.0.versions_count'));
        $this->assertSame(3, $response->json('children.0.subtree_versions_count'));
    }

    public function test_update_renames_without_moving_identity(): void
    {
        $this->actingAsUser();
        [$shelf] = $this->chain();

        $response = $this->patchJson('/api/locations/o1s1', ['name' => 'Top shelf']);

        $response->assertOk();
        $this->assertDatabaseHas('locations', [
            'location_id' => $shelf->location_id,
            'name' => 'Top shelf',
            'slug' => 'o1s1',
        ]);
    }

    public function test_moving_a_location_into_its_own_descendant_is_a_422(): void
    {
        $this->actingAsUser();
        [$shelf] = $this->chain();

        $response = $this->patchJson('/api/locations/o', ['parent_id' => $shelf->location_id]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason_code', 'location_cycle');
    }

    public function test_destroy_refuses_a_location_with_children_even_forced(): void
    {
        $this->actingAsUser();
        $this->chain();

        $response = $this->deleteJson('/api/locations/o1?force=true');

        $response->assertStatus(409);
        $response->assertJsonPath('reason_code', 'location_has_children');
        $this->assertDatabaseHas('locations', ['slug' => 'o1']);
    }

    public function test_destroy_refuses_a_shelving_location_without_force_then_unshelves_with_it(): void
    {
        $this->actingAsUser();
        [$shelf] = $this->chain();
        $version = Version::factory()->create(['location_id' => $shelf->location_id, 'shelf_ordinal' => 4]);

        $this->deleteJson('/api/locations/o1s1')
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'location_in_use');

        $this->deleteJson('/api/locations/o1s1?force=true')->assertOk();

        $this->assertDatabaseMissing('locations', ['slug' => 'o1s1']);
        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'location_id' => null,
            'shelf_ordinal' => null,
        ]);
    }

    public function test_books_covers_the_whole_subtree(): void
    {
        $this->actingAsUser();
        [$shelfOne, $bookcase] = $this->chain();
        $shelfTwo = Location::factory()->childOf($bookcase)->create(['code' => 'O1S2', 'slug' => 'o1s2']);
        $elsewhere = Location::factory()->create();

        Version::factory()->for(Book::factory()->create(['title' => 'On Shelf One']), 'book')
            ->create(['location_id' => $shelfOne->location_id]);
        Version::factory()->for(Book::factory()->create(['title' => 'On Shelf Two']), 'book')
            ->create(['location_id' => $shelfTwo->location_id]);
        Version::factory()->for(Book::factory()->create(['title' => 'Somewhere Else']), 'book')
            ->create(['location_id' => $elsewhere->location_id]);

        $response = $this->getJson('/api/locations/o1/books');

        $response->assertOk();
        $this->assertSame(2, $response->json('pagination.total'));
        $this->assertSame('O1', $response->json('location.code'));
    }

    public function test_a_shelf_lists_its_books_in_shelf_order_by_default(): void
    {
        $this->actingAsUser();
        [$shelf] = $this->chain();

        Version::factory()->for(Book::factory()->create(['title' => 'Rightmost']), 'book')
            ->create(['location_id' => $shelf->location_id, 'shelf_ordinal' => 9]);
        Version::factory()->for(Book::factory()->create(['title' => 'Leftmost']), 'book')
            ->create(['location_id' => $shelf->location_id, 'shelf_ordinal' => 1]);

        $response = $this->getJson('/api/locations/o1s1/books');

        $response->assertOk();
        $this->assertSame('Leftmost', $response->json('books.0.book.title'));
        $this->assertSame('Rightmost', $response->json('books.1.book.title'));
    }
}
