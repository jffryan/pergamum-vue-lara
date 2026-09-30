<?php

namespace Tests\Feature\Versions;

use App\Models\Book;
use App\Models\Format;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionsResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_new_version_persists_record(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        // Pinned to a format that carries a page count: the endpoint now
        // drops length fields the format doesn't expect, and the unstated
        // factory picks a name at random — including 'Audiobook'.
        $format = Format::factory()->print()->create();

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

    /**
     * Same door shape as `POST /create-book`: the copy is placed by the
     * service the picker uses, and the response carries what the book
     * page's copy row renders.
     */
    public function test_add_new_version_shelves_the_copy_on_the_named_location(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $format = Format::factory()->print()->create();
        $shelf = Location::factory()->create(['code' => 'O1S5', 'slug' => 'o1s5']);

        $response = $this->postJson('/api/versions', ['version' => [
            'book_id' => $book->book_id,
            'page_count' => 250,
            'format' => ['format_id' => $format->format_id],
            'location_id' => $shelf->location_id,
        ]]);

        $response->assertCreated()
            ->assertJsonPath('location_id', $shelf->location_id)
            ->assertJsonPath('location.slug', 'o1s5')
            ->assertJsonPath('format.format_id', $format->format_id)
            ->assertJsonPath('shelf_ordinal', null);
        $this->assertSame($shelf->location_id, $book->versions()->sole()->location_id);
    }

    public function test_add_new_version_without_a_location_is_unshelved(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $format = Format::factory()->print()->create();

        $this->postJson('/api/versions', ['version' => [
            'book_id' => $book->book_id,
            'page_count' => 250,
            'format' => ['format_id' => $format->format_id],
            'location_id' => null,
        ]])->assertCreated()->assertJsonPath('location', null);
    }

    public function test_add_new_version_rejects_an_unknown_location_and_writes_nothing(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $format = Format::factory()->print()->create();

        $this->postJson('/api/versions', ['version' => [
            'book_id' => $book->book_id,
            'page_count' => 250,
            'format' => ['format_id' => $format->format_id],
            'location_id' => 999999,
        ]])->assertStatus(422)->assertJsonValidationErrors('version.location_id');

        $this->assertSame(0, $book->versions()->count());
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
