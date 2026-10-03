<?php

namespace Tests\Feature\Books;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /api/books/bulk-tag` — the endpoint's own contract. The name rules it
 * shares with the other doors are pinned in `GenreIngestTest`.
 */
class BulkTagBooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_every_book_with_every_name(): void
    {
        $this->actingAsUser();
        $books = Book::factory()->count(2)->create();

        $response = $this->postJson('/api/books/bulk-tag', [
            'book_ids' => $books->pluck('book_id')->all(),
            'names' => ['Space Opera', 'Military'],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['book_ids', 'genres' => [['genre_id', 'name']]]);
        foreach ($books as $book) {
            $this->assertEqualsCanonicalizing(['Space Opera', 'Military'], $book->fresh()->genres->pluck('name')->all());
        }
        $this->assertSame(2, Genre::count(), 'the second book must reuse the genres the first created');
    }

    public function test_tags_by_genre_id_without_resolving_the_name(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        // A legacy row from direct SQL, never normalized. By name it would
        // resolve to `magical realism`, miss, and create a second genre.
        $spaced = Genre::factory()->create(['name' => 'magical  realism']);

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'genre_ids' => [$spaced->genre_id],
        ])->assertOk();

        $this->assertSame([$spaced->genre_id], $book->fresh()->genres->pluck('genre_id')->all());
        $this->assertSame(1, Genre::count());
    }

    public function test_combines_ids_and_names_and_dedupes_across_them(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $existing = Genre::factory()->create(['name' => 'Space Opera']);

        $response = $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'genre_ids' => [$existing->genre_id],
            'names' => ['space opera', 'Military'],
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'genres');
        $this->assertEqualsCanonicalizing(['Space Opera', 'Military'], $book->fresh()->genres->pluck('name')->all());
    }

    public function test_rejects_a_request_with_no_genres(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();

        $this->postJson('/api/books/bulk-tag', ['book_ids' => [$book->book_id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['genre_ids', 'names']);
    }

    public function test_rejects_an_unknown_genre_id(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'genre_ids' => [9999],
        ])->assertStatus(422)->assertJsonValidationErrors('genre_ids.0');

        $this->assertSame(0, $book->genres()->count());
    }

    public function test_is_additive_and_idempotent(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $kept = Genre::factory()->create(['name' => 'Kept']);
        $tag = Genre::factory()->create(['name' => 'Space Opera']);
        $book->genres()->attach([$kept->genre_id, $tag->genre_id]);

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'names' => ['Space Opera'],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(['Kept', 'Space Opera'], $book->fresh()->genres->pluck('name')->all());
        $this->assertSame(2, $book->genres()->count(), 'an already-held genre must not gain a second pivot row');
    }

    public function test_dedupes_repeated_book_ids(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();

        $response = $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id, $book->book_id],
            'names' => ['Space Opera'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('book_ids', [$book->book_id]);
        $this->assertSame(1, $book->genres()->count());
    }

    public function test_rejects_an_empty_selection(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/books/bulk-tag', ['book_ids' => [], 'names' => ['Space Opera']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('book_ids');

        $this->assertSame(0, Genre::count(), 'a rejected request must not create the genre');
    }

    public function test_rejects_an_unknown_book_and_writes_nothing(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id, 9999],
            'names' => ['Space Opera'],
        ])->assertStatus(422)->assertJsonValidationErrors('book_ids.1');

        $this->assertSame(0, $book->genres()->count());
    }

    public function test_rejects_a_blank_name_rather_than_dropping_it(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'names' => ['   '],
        ])->assertStatus(422)->assertJsonValidationErrors('names.0');

        $this->assertSame(0, Genre::count());
    }

    public function test_requires_authentication(): void
    {
        $book = Book::factory()->create();

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'names' => ['Space Opera'],
        ])->assertUnauthorized();
    }
}
