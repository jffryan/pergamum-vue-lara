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

    /**
     * The book forms read the capability flags off this payload to decide which
     * length inputs to render, so they are part of the projection's contract.
     */
    public function test_config_formats_returns_id_name_and_capabilities(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Audiobook']);
        Format::factory()->create(['name' => 'Ebook']);

        $response = $this->getJson('/api/config/formats');

        $response->assertOk();
        $payload = $response->json();
        $this->assertNotEmpty($payload);
        foreach ($payload as $row) {
            $this->assertEqualsCanonicalizing(
                ['format_id', 'name', 'expects_page_count', 'expects_audio_runtime'],
                array_keys($row),
            );
        }

        $audiobook = collect($payload)->firstWhere('name', 'Audiobook');
        $this->assertFalse($audiobook['expects_page_count']);
        $this->assertTrue($audiobook['expects_audio_runtime']);

        $ebook = collect($payload)->firstWhere('name', 'Ebook');
        $this->assertTrue($ebook['expects_page_count']);
        $this->assertFalse($ebook['expects_audio_runtime']);
    }

    public function test_store_defaults_to_a_print_format_and_accepts_overrides(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/formats', ['name' => 'Zine'])
            ->assertCreated()
            ->assertJsonPath('expects_page_count', true)
            ->assertJsonPath('expects_audio_runtime', false);

        $this->postJson('/api/formats', [
            'name' => 'Podcast',
            'expects_page_count' => false,
            'expects_audio_runtime' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('expects_page_count', false)
            ->assertJsonPath('expects_audio_runtime', true);
    }

    public function test_unauthenticated_store_is_rejected(): void
    {
        $this->postJson('/api/formats', ['name' => 'Whatever'])->assertUnauthorized();
    }
}
