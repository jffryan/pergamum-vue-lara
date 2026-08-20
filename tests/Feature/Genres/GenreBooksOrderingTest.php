<?php

namespace Tests\Feature\Genres;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /genres/{genre}` orders and eager-loads through `BookListing`, the same
 * query the library listing uses.
 *
 * It did not always: the detail page kept a join-and-GROUP-BY of its own that
 * ranked books by `MIN(last_name)` — the alphabetically first author — while
 * the table header says "Primary Author" and the row renders `authors[0]`.
 * These pin the two back together.
 */
class GenreBooksOrderingTest extends TestCase
{
    use RefreshDatabase;

    private Genre $genre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->genre = Genre::factory()->create(['name' => 'Sci-Fi']);
    }

    /**
     * A book in the genre with one author, one version, and optionally a read.
     */
    private function book(string $title, array $attrs = []): Book
    {
        $book = Book::factory()->create(['title' => $title]);
        $book->genres()->attach($this->genre->genre_id);

        $author = Author::factory()->create([
            'first_name' => $attrs['first_name'] ?? 'Ignored',
            'last_name' => $attrs['last_name'] ?? 'Zzyzx',
        ]);
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);

        $version = Version::factory()->for($book, 'book')->create();

        if (isset($attrs['date_read'])) {
            ReadInstance::factory()->create([
                'user_id' => $attrs['user_id'] ?? auth()->id(),
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => $attrs['date_read'],
                'rating' => $attrs['rating'] ?? null,
            ]);
        }

        return $book;
    }

    private function titles(string $url): array
    {
        $response = $this->getJson($url);
        $response->assertOk();

        return collect($response->json('books'))->pluck('book.title')->all();
    }

    private function url(array $query = []): string
    {
        $suffix = $query ? '?'.http_build_query($query) : '';

        return "/api/genres/{$this->genre->genre_id}{$suffix}";
    }

    public function test_books_are_ordered_by_primary_author_filing_name(): void
    {
        $this->actingAsUser();
        $this->book('Middle', ['last_name' => 'Mbeki']);
        $this->book('Last', ['last_name' => 'Zamora']);
        $this->book('First', ['last_name' => 'Abadi']);

        $this->assertSame(['First', 'Middle', 'Last'], $this->titles($this->url()));
    }

    /**
     * The whole point of the shared query: ordinal 1 is Vance, but the book's
     * authors start at Anderson alphabetically. The old `MIN(last_name)`
     * ordering filed this under A while the row rendered Vance.
     */
    public function test_it_files_a_collaboration_under_the_author_the_row_renders(): void
    {
        $this->actingAsUser();

        $collaboration = $this->book('Collaboration', ['last_name' => 'Vance']);
        $collaboration->authors()->attach(
            Author::factory()->create(['last_name' => 'Anderson'])->author_id,
            ['author_ordinal' => 2]
        );

        $this->book('Solo', ['last_name' => 'Marlowe']);

        $this->assertSame(['Solo', 'Collaboration'], $this->titles($this->url()));
    }

    /**
     * `authors[0]` is what `BookTableRow` labels "Primary Author", so the
     * eager load has to come back in ordinal order — otherwise the column
     * shows a name the ordering had nothing to do with.
     */
    public function test_the_first_eager_loaded_author_is_the_primary_one(): void
    {
        $this->actingAsUser();

        $book = $this->book('Collaboration', ['last_name' => 'Vance']);
        $book->authors()->attach(
            Author::factory()->create(['last_name' => 'Anderson'])->author_id,
            ['author_ordinal' => 2]
        );

        $response = $this->getJson($this->url());

        $response->assertOk();
        $this->assertSame('Vance', $response->json('books.0.authors.0.last_name'));
    }

    /**
     * Mononyms and organizations carry an empty `last_name`; filing them under
     * '' clumped them ahead of A. See `Author::sortNameExpression()`.
     */
    public function test_authors_without_a_last_name_file_under_their_first_name(): void
    {
        $this->actingAsUser();
        $this->book('Arendt', ['first_name' => 'Hannah', 'last_name' => 'Arendt']);
        $this->book('Aristotle', ['first_name' => 'Aristotle', 'last_name' => '']);
        $this->book('Armstrong', ['first_name' => 'Louis', 'last_name' => 'Armstrong']);

        $this->assertSame(
            ['Arendt', 'Aristotle', 'Armstrong'],
            $this->titles($this->url())
        );
    }

    /**
     * A book with no author at all has no sort key. NULL sorts first in MySQL
     * ascending, which would head the page with the least identifiable rows.
     */
    public function test_books_with_no_author_sort_last(): void
    {
        $this->actingAsUser();

        $orphan = Book::factory()->create(['title' => 'Anonymous']);
        $orphan->genres()->attach($this->genre->genre_id);
        Version::factory()->for($orphan, 'book')->create();

        $this->book('Attributed', ['last_name' => 'Zamora']);

        $this->assertSame(['Attributed', 'Anonymous'], $this->titles($this->url()));
    }

    public function test_pagination_does_not_repeat_or_drop_rows_when_the_sort_key_ties(): void
    {
        $this->actingAsUser();

        // Same filing name on every book, so the PK tiebreak is the only thing
        // keeping the pages disjoint.
        foreach (range(1, 6) as $n) {
            $this->book("Tied {$n}", ['last_name' => 'Tie']);
        }

        $first = $this->titles($this->url(['limit' => 3, 'page' => 1]));
        $second = $this->titles($this->url(['limit' => 3, 'page' => 2]));

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertEmpty(array_intersect($first, $second));
        $this->assertEqualsCanonicalizing(
            ['Tied 1', 'Tied 2', 'Tied 3', 'Tied 4', 'Tied 5', 'Tied 6'],
            array_merge($first, $second)
        );
    }

    /**
     * The old query left-joined `read_instances` raw, outside the reach of the
     * `BelongsToCurrentUser` global scope.
     */
    public function test_it_does_not_return_another_users_reads(): void
    {
        $stranger = User::factory()->create();
        $this->actingAsUser();

        $this->book('Theirs', ['date_read' => '2025-06-01', 'user_id' => $stranger->user_id]);

        $response = $this->getJson($this->url());

        $response->assertOk();
        $this->assertSame([], $response->json('books.0.readInstances'));
    }

    /**
     * `readInstances[0]` is the date and rating the row shows, so the most
     * recent read has to come first.
     */
    public function test_the_first_eager_loaded_read_is_the_most_recent(): void
    {
        $this->actingAsUser();

        $book = $this->book('Reread', ['date_read' => '2019-01-01', 'rating' => 10]);
        ReadInstance::factory()->create([
            'user_id' => auth()->id(),
            'book_id' => $book->book_id,
            'version_id' => $book->versions()->first()->version_id,
            'date_read' => '2025-01-01',
            'rating' => 2,
        ]);

        $response = $this->getJson($this->url());

        $response->assertOk();
        $this->assertSame('2025-01-01', $response->json('books.0.readInstances.0.date_read'));
    }

    public function test_it_only_returns_books_in_the_genre(): void
    {
        $this->actingAsUser();
        $this->book('In genre');

        $other = Book::factory()->create(['title' => 'Out of genre']);
        Version::factory()->for($other, 'book')->create();

        $this->assertSame(['In genre'], $this->titles($this->url()));
    }
}
