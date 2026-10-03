<?php

namespace Tests\Feature\Statistics;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `genre` statistics scope. A genre tags books, so every copy of a
 * tagged book is in scope and reads of any copy count.
 */
class GenreMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function taggedBook(Genre $genre, array $versionAttributes = []): Version
    {
        $version = Version::factory()->create($versionAttributes);
        Book::find($version->book_id)->genres()->attach($genre->genre_id);

        return $version;
    }

    public function test_scope_reports_over_the_genres_books(): void
    {
        $user = $this->actingAsUser();
        $genre = Genre::factory()->create();

        $read = $this->taggedBook($genre, ['page_count' => 300]);
        $this->taggedBook($genre, ['page_count' => 200]);
        Version::factory()->create(['page_count' => 999]); // untagged
        ReadInstance::factory()->create([
            'user_id' => $user->user_id,
            'version_id' => $read->version_id,
            'book_id' => $read->book_id,
            'rating' => 4,
        ]);

        $response = $this->getJson("/api/statistics/genre/{$genre->genre_id}")->assertOk();

        $this->assertSame('genre', $response->json('scope.type'));
        $this->assertSame(2, $response->json('metrics.totalItems'));
        $this->assertSame(500, $response->json('metrics.totalPages'));
        $this->assertSame(1, $response->json('metrics.completedCount'));
        $this->assertSame(50, $response->json('metrics.completedPercent'));
        $this->assertEquals(4, $response->json('metrics.averageRating'));
    }

    public function test_reads_only_count_for_the_requesting_user(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create();
        $version = $this->taggedBook($genre);
        ReadInstance::factory()->create([
            'version_id' => $version->version_id,
            'book_id' => $version->book_id,
            // someone else's read of this tagged book
        ]);

        $response = $this->getJson("/api/statistics/genre/{$genre->genre_id}")->assertOk();

        $this->assertSame(0, $response->json('metrics.completedCount'));
        $this->assertNull($response->json('metrics.averageRating'));
    }

    public function test_genre_breakdown_leaves_out_the_genre_itself(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create(['name' => 'history']);
        $other = Genre::factory()->create(['name' => 'nonfiction']);

        $version = $this->taggedBook($genre);
        Book::find($version->book_id)->genres()->attach($other->genre_id);
        $this->taggedBook($genre);

        $response = $this->getJson("/api/statistics/genre/{$genre->genre_id}?metrics=genreBreakdown")->assertOk();

        $this->assertSame(
            [['genre_id' => $other->genre_id, 'name' => 'nonfiction', 'count' => 1]],
            $response->json('metrics.genreBreakdown'),
        );
    }

    public function test_top_authors_counts_books_per_author_most_first(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create();
        $prolific = Author::factory()->create(['first_name' => 'Ann', 'last_name' => 'Prolific']);
        $once = Author::factory()->create(['first_name' => 'Ola', 'last_name' => 'Once']);

        foreach ([$prolific, $prolific, $once] as $author) {
            $version = $this->taggedBook($genre);
            Book::find($version->book_id)->authors()->attach($author->author_id);
        }

        // A second copy of a book is still one book for its author.
        Version::factory()->create(['book_id' => $version->book_id]);

        $response = $this->getJson("/api/statistics/genre/{$genre->genre_id}?metrics=topAuthors")->assertOk();

        $this->assertSame(
            [
                ['author_id' => $prolific->author_id, 'name' => 'Ann Prolific', 'slug' => $prolific->slug, 'count' => 2],
                ['author_id' => $once->author_id, 'name' => 'Ola Once', 'slug' => $once->slug, 'count' => 1],
            ],
            $response->json('metrics.topAuthors'),
        );
    }

    public function test_user_only_metrics_are_rejected_for_a_genre(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create();

        $this->getJson("/api/statistics/genre/{$genre->genre_id}?metrics=newestBooks")->assertUnprocessable();
    }

    public function test_an_unknown_genre_is_a_404(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/statistics/genre/999999')->assertNotFound();
        $this->getJson('/api/statistics/genre/not-an-id')->assertNotFound();
    }
}
