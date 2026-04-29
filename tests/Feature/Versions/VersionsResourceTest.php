<?php

namespace Tests\Feature\Versions;

use App\Models\Book;
use App\Models\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionsResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_new_version_persists_record(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $format = Format::factory()->create();

        $payload = [
            'version' => [
                'book_id' => $book->book_id,
                'page_count' => 250,
                'audio_runtime' => null,
                'format' => ['format_id' => $format->format_id],
            ],
        ];

        $response = $this->postJson('/api/versions', $payload);

        $response->assertCreated()
            ->assertJsonPath('book_id', $book->book_id)
            ->assertJsonPath('format_id', $format->format_id)
            ->assertJsonPath('page_count', 250);
        $this->assertDatabaseHas('versions', [
            'book_id' => $book->book_id,
            'format_id' => $format->format_id,
            'page_count' => 250,
        ]);
    }

    public function test_missing_required_fields_returns_validation_error(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/versions', ['version' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['version.book_id', 'version.format.format_id']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/versions', ['version' => []])->assertUnauthorized();
    }
}
