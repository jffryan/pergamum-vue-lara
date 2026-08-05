<?php

namespace Tests\Feature\Genres;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenresCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_genre(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/genres', ['name' => 'Essay']);

        $response->assertStatus(201);
        $response->assertJsonStructure(['genre_id', 'name', 'books_count']);
        $this->assertDatabaseHas('genres', ['name' => 'Essay']);
    }

    public function test_create_collapses_surrounding_and_internal_whitespace(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/genres', ['name' => "  science   fiction \n"]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('genres', ['name' => 'science fiction']);
    }

    public function test_create_with_colliding_name_returns_409_carrying_the_conflict(): void
    {
        $this->actingAsUser();
        $existing = Genre::factory()->create(['name' => 'Essay']);

        $response = $this->postJson('/api/genres', ['name' => 'Essay']);

        $response->assertStatus(409);
        $response->assertJsonPath('reason_code', 'genre_name_taken');
        $response->assertJsonPath('conflict.genre_id', $existing->genre_id);
        $this->assertSame(1, Genre::where('name', 'Essay')->count());
    }

    public function test_create_rejects_a_whitespace_only_name(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/genres', ['name' => '     ']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame(0, Genre::count());
    }

    public function test_rename_genre(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create(['name' => 'essays']);

        $response = $this->patchJson("/api/genres/{$genre->genre_id}", ['name' => 'Personal essays']);

        $response->assertOk();
        $response->assertJsonStructure(['genre_id', 'name', 'books_count']);
        $this->assertDatabaseHas('genres', ['genre_id' => $genre->genre_id, 'name' => 'Personal essays']);
    }

    public function test_rename_to_a_colliding_name_returns_409(): void
    {
        $this->actingAsUser();
        $essay = Genre::factory()->create(['name' => 'essay']);
        $essays = Genre::factory()->create(['name' => 'essays']);

        $response = $this->patchJson("/api/genres/{$essays->genre_id}", ['name' => 'essay']);

        $response->assertStatus(409);
        $response->assertJsonPath('reason_code', 'genre_name_taken');
        $response->assertJsonPath('conflict.genre_id', $essay->genre_id);
        $this->assertDatabaseHas('genres', ['genre_id' => $essays->genre_id, 'name' => 'essays']);
    }

    public function test_renaming_a_genre_to_its_own_current_name_is_not_a_conflict(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create(['name' => 'essay']);

        $response = $this->patchJson("/api/genres/{$genre->genre_id}", ['name' => 'essay']);

        $response->assertOk();
        $this->assertDatabaseHas('genres', ['genre_id' => $genre->genre_id, 'name' => 'essay']);
    }

    /**
     * The conflict check is case- and trailing-space-insensitive only because
     * the connection collation is `utf8mb4_unicode_ci`. Nothing in PHP enforces
     * it, so a collation change would silently turn the guard off — this is what
     * would catch that.
     */
    public function test_rename_conflict_is_case_insensitive(): void
    {
        $this->actingAsUser();
        $essays = Genre::factory()->create(['name' => 'Essays']);
        $target = Genre::factory()->create(['name' => 'memoir']);

        $response = $this->patchJson("/api/genres/{$target->genre_id}", ['name' => 'essays']);

        $response->assertStatus(409);
        $response->assertJsonPath('conflict.genre_id', $essays->genre_id);
    }

    public function test_rename_conflict_ignores_trailing_whitespace(): void
    {
        $this->actingAsUser();
        $essays = Genre::factory()->create(['name' => 'essays']);
        $target = Genre::factory()->create(['name' => 'memoir']);

        $response = $this->patchJson("/api/genres/{$target->genre_id}", ['name' => 'essays   ']);

        $response->assertStatus(409);
        $response->assertJsonPath('conflict.genre_id', $essays->genre_id);
    }

    public function test_delete_an_unused_genre(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create();

        $response = $this->deleteJson("/api/genres/{$genre->genre_id}");

        $response->assertOk();
        $response->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('genres', ['genre_id' => $genre->genre_id]);
    }

    public function test_delete_a_genre_with_books_requires_force(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create();
        Book::factory()->count(2)->create()->each(
            fn (Book $book) => $book->genres()->attach($genre->genre_id)
        );

        $response = $this->deleteJson("/api/genres/{$genre->genre_id}");

        $response->assertStatus(409);
        $response->assertJsonPath('reason_code', 'genre_in_use');
        $response->assertJsonPath('books_count', 2);
        $this->assertDatabaseHas('genres', ['genre_id' => $genre->genre_id]);
    }

    public function test_delete_with_force_removes_the_genre_and_its_pivot_rows(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create();
        $book = Book::factory()->create();
        $book->genres()->attach($genre->genre_id);

        $response = $this->deleteJson("/api/genres/{$genre->genre_id}?force=true");

        $response->assertOk();
        $this->assertDatabaseMissing('genres', ['genre_id' => $genre->genre_id]);
        $this->assertDatabaseMissing('book_genre', ['genre_id' => $genre->genre_id]);
        $this->assertDatabaseHas('books', ['book_id' => $book->book_id]);
    }
}
