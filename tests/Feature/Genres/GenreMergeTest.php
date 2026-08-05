<?php

namespace Tests\Feature\Genres;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenreMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_repoints_books_from_the_losers_to_the_winner(): void
    {
        $this->actingAsUser();
        $keep = Genre::factory()->create(['name' => 'essay']);
        $loserA = Genre::factory()->create(['name' => 'essays']);
        $loserB = Genre::factory()->create(['name' => 'the essay']);

        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $bookA->genres()->attach($loserA->genre_id);
        $bookB->genres()->attach($loserB->genre_id);

        $response = $this->postJson("/api/genres/{$keep->genre_id}/merge", [
            'source_ids' => [$loserA->genre_id, $loserB->genre_id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('books_count', 2);
        $this->assertDatabaseHas('book_genre', ['book_id' => $bookA->book_id, 'genre_id' => $keep->genre_id]);
        $this->assertDatabaseHas('book_genre', ['book_id' => $bookB->book_id, 'genre_id' => $keep->genre_id]);
    }

    /**
     * The regression this merge design exists to prevent. `book_genre` has no
     * unique constraint on (book_id, genre_id), so `UPDATE book_genre SET
     * genre_id = <winner>` would leave a book tagged with *both* genres holding
     * two rows for the winner. That duplicate is invisible in `GenreBreakdown`
     * (which counts DISTINCT book_id) but over-reports in the
     * `withCount('books')` the admin screen displays.
     */
    public function test_a_book_tagged_with_both_winner_and_loser_keeps_exactly_one_pivot_row(): void
    {
        $this->actingAsUser();
        $keep = Genre::factory()->create(['name' => 'essay']);
        $loser = Genre::factory()->create(['name' => 'essays']);

        $book = Book::factory()->create();
        $book->genres()->attach([$keep->genre_id, $loser->genre_id]);

        $response = $this->postJson("/api/genres/{$keep->genre_id}/merge", [
            'source_ids' => [$loser->genre_id],
        ]);

        $response->assertOk();
        $this->assertSame(1, DB::table('book_genre')
            ->where('book_id', $book->book_id)
            ->where('genre_id', $keep->genre_id)
            ->count());
        $response->assertJsonPath('books_count', 1);
    }

    public function test_merge_deletes_the_losing_genres_and_their_pivot_rows(): void
    {
        $this->actingAsUser();
        $keep = Genre::factory()->create();
        $loser = Genre::factory()->create();
        Book::factory()->create()->genres()->attach($loser->genre_id);

        $this->postJson("/api/genres/{$keep->genre_id}/merge", ['source_ids' => [$loser->genre_id]])
            ->assertOk();

        $this->assertDatabaseMissing('genres', ['genre_id' => $loser->genre_id]);
        $this->assertDatabaseMissing('book_genre', ['genre_id' => $loser->genre_id]);
        $this->assertDatabaseHas('genres', ['genre_id' => $keep->genre_id]);
    }

    public function test_merging_a_genre_into_itself_is_rejected(): void
    {
        $this->actingAsUser();
        $keep = Genre::factory()->create();

        $response = $this->postJson("/api/genres/{$keep->genre_id}/merge", [
            'source_ids' => [$keep->genre_id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('source_ids.0');
        $this->assertDatabaseHas('genres', ['genre_id' => $keep->genre_id]);
    }

    public function test_merge_rejects_unknown_source_ids(): void
    {
        $this->actingAsUser();
        $keep = Genre::factory()->create();

        $response = $this->postJson("/api/genres/{$keep->genre_id}/merge", [
            'source_ids' => [9999999],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('source_ids.0');
    }

    public function test_merge_requires_at_least_one_source(): void
    {
        $this->actingAsUser();
        $keep = Genre::factory()->create();

        $this->postJson("/api/genres/{$keep->genre_id}/merge", ['source_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_ids');
    }

    public function test_books_count_on_the_response_reflects_the_union(): void
    {
        $this->actingAsUser();
        $keep = Genre::factory()->create();
        $loser = Genre::factory()->create();

        $shared = Book::factory()->create();
        $onlyKeep = Book::factory()->create();
        $onlyLoser = Book::factory()->create();

        $shared->genres()->attach([$keep->genre_id, $loser->genre_id]);
        $onlyKeep->genres()->attach($keep->genre_id);
        $onlyLoser->genres()->attach($loser->genre_id);

        $response = $this->postJson("/api/genres/{$keep->genre_id}/merge", [
            'source_ids' => [$loser->genre_id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('books_count', 3);
        $this->assertSame(3, DB::table('book_genre')->where('genre_id', $keep->genre_id)->count());
    }
}
