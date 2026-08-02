<?php

namespace Tests\Feature\Versions;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscardVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_discard_without_a_date_records_the_state_only(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        $response = $this->patchJson("/api/versions/{$version->version_id}/discard");

        $response->assertOk()
            ->assertJsonPath('is_discarded', true)
            ->assertJsonPath('discarded_at', null);
        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'is_discarded' => 1,
            'discarded_at' => null,
        ]);
    }

    public function test_discard_accepts_an_optional_date(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        $response = $this->patchJson(
            "/api/versions/{$version->version_id}/discard",
            ['discarded_at' => '2019-04-01']
        );

        $response->assertOk()->assertJsonPath('discarded_at', '2019-04-01');
        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'is_discarded' => 1,
            'discarded_at' => '2019-04-01',
        ]);
    }

    public function test_discard_explicitly_null_date_clears_an_existing_date(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->discarded('2019-04-01')->create();

        $this->patchJson(
            "/api/versions/{$version->version_id}/discard",
            ['discarded_at' => null]
        )->assertOk()->assertJsonPath('discarded_at', null);

        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'discarded_at' => null,
        ]);
    }

    public function test_discard_rejects_an_unparseable_date(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->create();

        $this->patchJson(
            "/api/versions/{$version->version_id}/discard",
            ['discarded_at' => 'sometime in the nineties']
        )->assertStatus(422)->assertJsonValidationErrors(['discarded_at']);
    }

    public function test_restore_clears_both_the_flag_and_the_date(): void
    {
        $this->actingAsUser();
        $version = Version::factory()->discarded('2019-04-01')->create();

        $response = $this->patchJson("/api/versions/{$version->version_id}/restore");

        $response->assertOk()
            ->assertJsonPath('is_discarded', false)
            ->assertJsonPath('discarded_at', null);
        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'is_discarded' => 0,
            'discarded_at' => null,
        ]);
    }

    public function test_discarding_an_unknown_version_returns_not_found(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/versions/999999/discard')->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $version = Version::factory()->create();

        $this->patchJson("/api/versions/{$version->version_id}/discard")->assertUnauthorized();
        $this->patchJson("/api/versions/{$version->version_id}/restore")->assertUnauthorized();
    }
}
