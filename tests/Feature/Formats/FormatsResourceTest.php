<?php

namespace Tests\Feature\Formats;

use App\Models\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormatsResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_format_with_slug(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/formats', ['name' => 'Graphic Novel']);

        $response->assertCreated()
            ->assertJsonPath('name', 'Graphic Novel')
            ->assertJsonPath('slug', 'graphic-novel');
        $this->assertDatabaseHas('formats', ['name' => 'Graphic Novel', 'slug' => 'graphic-novel']);
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);

        $this->postJson('/api/formats', ['name' => 'Hardcover'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_config_formats_returns_id_and_name(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Audiobook']);
        Format::factory()->create(['name' => 'Ebook']);

        $response = $this->getJson('/api/config/formats');

        $response->assertOk();
        $payload = $response->json();
        $this->assertNotEmpty($payload);
        foreach ($payload as $row) {
            $this->assertEqualsCanonicalizing(['format_id', 'name'], array_keys($row));
        }
    }

    public function test_unauthenticated_store_is_rejected(): void
    {
        $this->postJson('/api/formats', ['name' => 'Whatever'])->assertUnauthorized();
    }
}
