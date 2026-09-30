<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The virtual locations — `unshelved` (the holding pen) and `discarded`
 * (the pile). Neither is a `locations` row: both are derived from the
 * version's own columns, which is what keeps them in sync with the discard
 * flow without a second write.
 */
class VirtualLocationsTest extends TestCase
{
    use RefreshDatabase;

    /** One copy on a shelf, one unshelved, one discarded. */
    private function copies(): array
    {
        $shelf = Location::factory()->create();
        $shelved = Version::factory()->create(['location_id' => $shelf->location_id]);
        $unshelved = Version::factory()->create();
        $discarded = Version::factory()->create(['is_discarded' => true]);

        return [$shelf, $shelved, $unshelved, $discarded];
    }

    public function test_index_appends_the_virtual_locations_with_live_counts(): void
    {
        $this->actingAsUser();
        $this->copies();

        $response = $this->getJson('/api/locations');

        $response->assertOk();
        $response->assertJsonCount(3);
        $rows = collect($response->json());
        $this->assertSame(1, $rows->firstWhere('slug', 'unshelved')['versions_count']);
        $this->assertSame(1, $rows->firstWhere('slug', 'discarded')['versions_count']);
        $this->assertTrue($rows->firstWhere('slug', 'unshelved')['virtual']);
        $this->assertNull($rows->firstWhere('slug', 'unshelved')['location_id']);
    }

    public function test_show_unshelved_has_the_location_page_shape(): void
    {
        $this->actingAsUser();
        $this->copies();

        $response = $this->getJson('/api/locations/unshelved');

        $response->assertOk();
        $response->assertJsonStructure(['location', 'ancestors', 'children', 'subtree_versions_count']);
        $response->assertJsonPath('location.virtual', true);
        $response->assertJsonPath('subtree_versions_count', 1);
    }

    public function test_unshelved_books_lists_only_copies_with_no_location_that_are_not_discarded(): void
    {
        $this->actingAsUser();
        [, , $unshelved] = $this->copies();

        $response = $this->getJson('/api/locations/unshelved/books');

        $response->assertOk();
        $response->assertJsonCount(1, 'books');
        $this->assertSame($unshelved->version_id, $response->json('books.0.versions.0.version_id'));
    }

    public function test_discarded_books_lists_only_discarded_copies(): void
    {
        $this->actingAsUser();
        [, , , $discarded] = $this->copies();

        $response = $this->getJson('/api/locations/discarded/books');

        $response->assertOk();
        $response->assertJsonCount(1, 'books');
        $this->assertSame($discarded->version_id, $response->json('books.0.versions.0.version_id'));
    }

    public function test_discarding_a_shelved_copy_moves_it_to_the_pile(): void
    {
        $this->actingAsUser();
        [, $shelved] = $this->copies();

        $this->patchJson("/api/versions/{$shelved->version_id}/discard")->assertOk();

        $ids = collect($this->getJson('/api/locations/discarded/books')->json('books'))
            ->pluck('versions.0.version_id');
        $this->assertContains($shelved->version_id, $ids);
        $this->assertNull($shelved->fresh()->location_id);
    }

    public function test_a_discarded_copy_cannot_be_shelved(): void
    {
        $this->actingAsUser();
        [$shelf, , , $discarded] = $this->copies();

        $response = $this->patchJson("/api/versions/{$discarded->version_id}/location", [
            'location_id' => $shelf->location_id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('reason_code', 'copy_discarded');
        $this->assertNull($discarded->fresh()->location_id);
    }

    public function test_a_reserved_code_cannot_become_a_real_location(): void
    {
        $this->actingAsUser();

        // Mixed case on purpose — reservation is at the slug, like conflicts.
        $response = $this->postJson('/api/locations', ['code' => 'Unshelved', 'kind' => 'shelf']);

        $response->assertStatus(422);
        $response->assertJsonPath('reason_code', 'location_code_reserved');
        $this->assertDatabaseMissing('locations', ['slug' => 'unshelved']);
    }

    public function test_a_real_location_cannot_be_recoded_onto_a_reserved_slug(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();

        $this->patchJson("/api/locations/{$shelf->slug}", ['code' => 'discarded'])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'location_code_reserved');
    }
}
