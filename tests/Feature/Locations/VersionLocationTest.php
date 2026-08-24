<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shelving a copy: `PATCH /api/versions/{version}/location`, and the discard
 * flow's interaction with it (a copy you no longer own is not on a shelf).
 */
class VersionLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_copy_can_be_shelved_with_a_position(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();
        $version = Version::factory()->create();

        $response = $this->patchJson("/api/versions/{$version->version_id}/location", [
            'location_id' => $shelf->location_id,
            'shelf_ordinal' => 3,
        ]);

        $response->assertOk();
        $response->assertJsonPath('location.location_id', $shelf->location_id);
        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'location_id' => $shelf->location_id,
            'shelf_ordinal' => 3,
        ]);
    }

    public function test_a_null_location_unshelves_and_clears_the_position(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();
        $version = Version::factory()->create(['location_id' => $shelf->location_id, 'shelf_ordinal' => 3]);

        $this->patchJson("/api/versions/{$version->version_id}/location", ['location_id' => null])
            ->assertOk();

        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'location_id' => null,
            'shelf_ordinal' => null,
        ]);
    }

    public function test_omitting_location_id_entirely_is_a_422(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        // Unshelving must be an explicit null, never an accidental omission.
        $this->patchJson("/api/versions/{$version->version_id}/location", ['shelf_ordinal' => 1])
            ->assertStatus(422);
    }

    public function test_an_unknown_location_is_a_422(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        $this->patchJson("/api/versions/{$version->version_id}/location", ['location_id' => 999999])
            ->assertStatus(422);
    }

    public function test_discarding_a_copy_takes_it_off_its_shelf(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();
        $version = Version::factory()->create(['location_id' => $shelf->location_id, 'shelf_ordinal' => 2]);

        $this->patchJson("/api/versions/{$version->version_id}/discard")->assertOk();

        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'is_discarded' => true,
            'location_id' => null,
            'shelf_ordinal' => null,
        ]);
    }

    public function test_restoring_a_discarded_copy_does_not_reshelve_it(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();
        $version = Version::factory()->create(['location_id' => $shelf->location_id]);

        $this->patchJson("/api/versions/{$version->version_id}/discard")->assertOk();
        $this->patchJson("/api/versions/{$version->version_id}/restore")->assertOk();

        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'is_discarded' => false,
            'location_id' => null,
        ]);
    }
}
