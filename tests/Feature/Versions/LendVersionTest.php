<?php

namespace Tests\Feature\Versions;

use App\Models\Location;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lending a copy: still owned, currently elsewhere. The shelf is the copy's
 * home and survives the loan; discarding ends one.
 */
class LendVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lend_without_details_records_the_state_only(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        $response = $this->patchJson("/api/versions/{$version->version_id}/lend");

        $response->assertOk()
            ->assertJsonPath('is_on_loan', true)
            ->assertJsonPath('loaned_to', null)
            ->assertJsonPath('loaned_at', null);
    }

    public function test_lend_records_who_and_when(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        $this->patchJson("/api/versions/{$version->version_id}/lend", [
            'loaned_to' => '  Priya from book club ',
            'loaned_at' => '2026-09-01',
        ])->assertOk()
            ->assertJsonPath('loaned_to', 'Priya from book club')
            ->assertJsonPath('loaned_at', '2026-09-01');

        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'is_on_loan' => 1,
            'loaned_to' => 'Priya from book club',
            'loaned_at' => '2026-09-01',
        ]);
    }

    public function test_lending_keeps_the_copy_on_its_shelf(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();
        $version = Version::factory()->create(['location_id' => $shelf->location_id, 'shelf_ordinal' => 4]);

        $this->patchJson("/api/versions/{$version->version_id}/lend", ['loaned_to' => 'Sam'])
            ->assertOk()
            ->assertJsonPath('location.location_id', $shelf->location_id);

        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'location_id' => $shelf->location_id,
            'shelf_ordinal' => 4,
        ]);
    }

    public function test_re_lending_without_a_key_keeps_the_existing_value(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->onLoan('Sam', '2026-01-01')->create();

        $this->patchJson("/api/versions/{$version->version_id}/lend", ['loaned_to' => 'Alex'])
            ->assertOk()
            ->assertJsonPath('loaned_to', 'Alex')
            ->assertJsonPath('loaned_at', '2026-01-01');
    }

    public function test_re_lending_with_an_explicit_null_clears_the_value(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->onLoan('Sam', '2026-01-01')->create();

        $this->patchJson("/api/versions/{$version->version_id}/lend", ['loaned_to' => null])
            ->assertOk()
            ->assertJsonPath('loaned_to', null)
            ->assertJsonPath('loaned_at', '2026-01-01');
    }

    public function test_lend_rejects_bad_input(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        $this->patchJson("/api/versions/{$version->version_id}/lend", [
            'loaned_to' => str_repeat('x', 256),
            'loaned_at' => 'whenever',
        ])->assertStatus(422)->assertJsonValidationErrors(['loaned_to', 'loaned_at']);
    }

    public function test_a_discarded_copy_cannot_be_lent(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->discarded()->create();

        $this->patchJson("/api/versions/{$version->version_id}/lend", ['loaned_to' => 'Sam'])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'copy_discarded');

        $this->assertFalse($version->fresh()->is_on_loan);
    }

    public function test_return_clears_the_loan_and_leaves_the_shelf(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();
        $version = Version::factory()->onLoan('Sam', '2026-01-01')->create(['location_id' => $shelf->location_id]);

        $this->patchJson("/api/versions/{$version->version_id}/return")
            ->assertOk()
            ->assertJsonPath('is_on_loan', false)
            ->assertJsonPath('loaned_to', null)
            ->assertJsonPath('loaned_at', null)
            ->assertJsonPath('location.location_id', $shelf->location_id);
    }

    public function test_discarding_a_lent_copy_ends_the_loan(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->onLoan('Sam', '2026-01-01')->create();

        $this->patchJson("/api/versions/{$version->version_id}/discard")
            ->assertOk()
            ->assertJsonPath('is_discarded', true)
            ->assertJsonPath('is_on_loan', false)
            ->assertJsonPath('loaned_to', null)
            ->assertJsonPath('loaned_at', null);
    }

    public function test_a_lent_copy_can_be_moved_to_another_shelf(): void
    {
        $this->actingAsUser();
        $shelf = Location::factory()->create();
        $version = Version::factory()->onLoan('Sam')->create();

        $this->patchJson("/api/versions/{$version->version_id}/location", ['location_id' => $shelf->location_id])
            ->assertOk();

        $this->assertSame($shelf->location_id, $version->fresh()->location_id);
        $this->assertTrue($version->fresh()->is_on_loan);
    }

    public function test_lending_an_unknown_version_returns_not_found(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/versions/999999/lend')->assertNotFound();
        $this->patchJson('/api/versions/999999/return')->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $version = Version::factory()->create();

        $this->patchJson("/api/versions/{$version->version_id}/lend")->assertUnauthorized();
        $this->patchJson("/api/versions/{$version->version_id}/return")->assertUnauthorized();
    }
}
