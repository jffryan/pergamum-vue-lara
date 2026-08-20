<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /books` ordering: `?sort=` against the whitelist in BookController,
 * `?direction=`, and the absent-values-last rule.
 *
 * Sorting is server-side because the listing is paginated — ordering the
 * twenty rows of the current page would rank a slice, not the library.
 */
class LibrarySortingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A book with one author, one version, and optionally one read.
     *
     * Every sortable dimension is reachable from here, so each test varies the
     * one it cares about and leaves the rest at a fixed value — otherwise a
     * tie-break on an unrelated column explains the assertion instead of the
     * sort under test.
     */
    private function book(string $title, array $attrs = []): Book
    {
        $book = Book::factory()->create(['title' => $title]);

        $author = Author::factory()->create([
            'first_name' => $attrs['first_name'] ?? 'Ignored',
            'last_name' => $attrs['last_name'] ?? 'Zzyzx',
        ]);
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);

        $format = Format::factory()->create([
            'name' => $attrs['format'] ?? 'Paperback',
        ]);
        $version = Version::factory()->for($book, 'book')->create([
            'format_id' => $format->format_id,
            'page_count' => $attrs['page_count'] ?? 100,
        ]);

        if (isset($attrs['date_read']) || isset($attrs['rating'])) {
            ReadInstance::factory()->create([
                'user_id' => $attrs['user_id'] ?? auth()->id(),
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => $attrs['date_read'] ?? '2024-01-01',
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

    public function test_it_defaults_to_primary_author_last_name_ascending(): void
    {
        $this->actingAsUser();
        $this->book('Middle', ['last_name' => 'Mbeki']);
        $this->book('Last', ['last_name' => 'Zamora']);
        $this->book('First', ['last_name' => 'Abadi']);

        $this->assertSame(['First', 'Middle', 'Last'], $this->titles('/api/books'));
    }

    public function test_it_sorts_by_title(): void
    {
        $this->actingAsUser();
        $this->book('Beta');
        $this->book('Alpha');
        $this->book('Gamma');

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $this->titles('/api/books?sort=title'));
        $this->assertSame(
            ['Gamma', 'Beta', 'Alpha'],
            $this->titles('/api/books?sort=title&direction=desc')
        );
    }

    public function test_it_sorts_by_author_last_name(): void
    {
        $this->actingAsUser();
        $this->book('Beta', ['last_name' => 'Brahe']);
        $this->book('Alpha', ['last_name' => 'Aurelius']);

        $this->assertSame(['Alpha', 'Beta'], $this->titles('/api/books?sort=author'));
        $this->assertSame(
            ['Beta', 'Alpha'],
            $this->titles('/api/books?sort=author&direction=desc')
        );
    }

    /**
     * Mononyms and organizations are stored with a first name and an empty
     * last name, so ordering on `last_name` alone filed all of them under ''
     * — a clump ahead of A, internally ordered by nothing the reader can see.
     * They belong in the same sequence as everyone else, under their one name.
     */
    public function test_authors_without_a_last_name_file_under_their_first_name(): void
    {
        $this->actingAsUser();
        $this->book('Arendt', ['first_name' => 'Hannah', 'last_name' => 'Arendt']);
        $this->book('Aristotle', ['first_name' => 'Aristotle', 'last_name' => '']);
        $this->book('Armstrong', ['first_name' => 'Louis', 'last_name' => 'Armstrong']);
        $this->book('Plato', ['first_name' => 'Plato', 'last_name' => '']);
        $this->book('NatGeo', ['first_name' => 'National Geographic', 'last_name' => '']);

        $this->assertSame(
            ['Arendt', 'Aristotle', 'Armstrong', 'NatGeo', 'Plato'],
            $this->titles('/api/books?sort=author')
        );
    }

    /**
     * Whitespace-only is the same absence as empty — a stray space in an
     * import would otherwise sort ahead of every real name.
     */
    public function test_a_whitespace_only_last_name_counts_as_absent(): void
    {
        $this->actingAsUser();
        $this->book('Beta', ['first_name' => 'Bede', 'last_name' => '   ']);
        $this->book('Alpha', ['first_name' => 'Marcus', 'last_name' => 'Aurelius']);

        $this->assertSame(['Alpha', 'Beta'], $this->titles('/api/books?sort=author'));
    }

    /**
     * The primary-author rule outranks the filing name: a book whose ordinal-1
     * author is a mononym sorts under that name, not under a co-author's
     * surname that happens to sort earlier.
     */
    public function test_a_mononym_primary_author_still_beats_a_later_co_author(): void
    {
        $this->actingAsUser();

        $collaboration = $this->book('Collaboration', ['first_name' => 'Plato', 'last_name' => '']);
        $collaboration->authors()->attach(
            Author::factory()->create(['first_name' => 'Kurt', 'last_name' => 'Anderson'])->author_id,
            ['author_ordinal' => 2]
        );

        $this->book('Solo', ['first_name' => 'Philip', 'last_name' => 'Marlowe']);

        $this->assertSame(['Solo', 'Collaboration'], $this->titles('/api/books?sort=author'));
    }

    public function test_it_sorts_by_the_primary_authors_last_name_not_the_alphabetical_first(): void
    {
        $this->actingAsUser();

        // Ordinal 1 is Vance; alphabetically the book's authors start at
        // Anderson. Sorting has to agree with the name the row renders.
        $collaboration = $this->book('Collaboration', ['last_name' => 'Vance']);
        $collaboration->authors()->attach(
            Author::factory()->create(['last_name' => 'Anderson'])->author_id,
            ['author_ordinal' => 2]
        );

        $this->book('Solo', ['last_name' => 'Marlowe']);

        $this->assertSame(['Solo', 'Collaboration'], $this->titles('/api/books?sort=author'));
    }

    public function test_it_sorts_by_page_count(): void
    {
        $this->actingAsUser();
        $this->book('Long', ['page_count' => 900]);
        $this->book('Short', ['page_count' => 120]);
        $this->book('Medium', ['page_count' => 400]);

        $this->assertSame(['Short', 'Medium', 'Long'], $this->titles('/api/books?sort=pages'));
        $this->assertSame(
            ['Long', 'Medium', 'Short'],
            $this->titles('/api/books?sort=pages&direction=desc')
        );
    }

    public function test_it_sorts_by_format_name(): void
    {
        $this->actingAsUser();
        $this->book('Listened', ['format' => 'Audiobook']);
        $this->book('Read', ['format' => 'Paperback']);
        $this->book('Shelved', ['format' => 'Hardcover']);

        $this->assertSame(
            ['Listened', 'Shelved', 'Read'],
            $this->titles('/api/books?sort=format')
        );
    }

    public function test_it_sorts_by_most_recent_date_read(): void
    {
        $this->actingAsUser();
        $this->book('Oldest', ['date_read' => '2019-03-04']);
        $this->book('Newest', ['date_read' => '2025-11-30']);
        $this->book('Middle', ['date_read' => '2022-07-15']);

        $this->assertSame(
            ['Oldest', 'Middle', 'Newest'],
            $this->titles('/api/books?sort=date_read')
        );
        $this->assertSame(
            ['Newest', 'Middle', 'Oldest'],
            $this->titles('/api/books?sort=date_read&direction=desc')
        );
    }

    public function test_it_sorts_by_rating(): void
    {
        $this->actingAsUser();
        // Ratings are stored doubled — 8 is four stars. The sort runs on the
        // raw column, which is fine because doubling preserves order.
        $this->book('Loved', ['rating' => 10, 'date_read' => '2024-01-01']);
        $this->book('Hated', ['rating' => 2, 'date_read' => '2024-01-01']);
        $this->book('Fine', ['rating' => 6, 'date_read' => '2024-01-01']);

        $this->assertSame(['Hated', 'Fine', 'Loved'], $this->titles('/api/books?sort=rating'));
        $this->assertSame(
            ['Loved', 'Fine', 'Hated'],
            $this->titles('/api/books?sort=rating&direction=desc')
        );
    }

    public function test_rating_resolves_against_the_most_recent_read_not_the_best_one(): void
    {
        $this->actingAsUser();

        // Reread and liked it less. The row shows the latest rating, so the
        // sort has to rank on that rather than on the high-water mark.
        $soured = $this->book('Soured', ['rating' => 10, 'date_read' => '2020-01-01']);
        ReadInstance::factory()->create([
            'user_id' => auth()->id(),
            'book_id' => $soured->book_id,
            'version_id' => $soured->versions()->first()->version_id,
            'date_read' => '2025-01-01',
            'rating' => 2,
        ]);

        $this->book('Steady', ['rating' => 6, 'date_read' => '2024-01-01']);

        $this->assertSame(['Soured', 'Steady'], $this->titles('/api/books?sort=rating'));
    }

    public function test_books_with_no_value_sort_last_in_both_directions(): void
    {
        $this->actingAsUser();
        $this->book('Unread');
        $this->book('Read Early', ['date_read' => '2019-01-01']);
        $this->book('Read Late', ['date_read' => '2025-01-01']);

        $this->assertSame(
            ['Read Early', 'Read Late', 'Unread'],
            $this->titles('/api/books?sort=date_read')
        );
        $this->assertSame(
            ['Read Late', 'Read Early', 'Unread'],
            $this->titles('/api/books?sort=date_read&direction=desc')
        );
    }

    public function test_read_derived_sorts_ignore_other_users_reads(): void
    {
        $stranger = User::factory()->create();
        $this->actingAsUser();

        // The stranger read this one recently; the signed-in user never did.
        // A sort that saw their row would rank it first.
        $this->book('Theirs', ['date_read' => '2025-06-01', 'user_id' => $stranger->user_id]);
        $this->book('Mine', ['date_read' => '2019-01-01']);

        $this->assertSame(
            ['Mine', 'Theirs'],
            $this->titles('/api/books?sort=date_read&direction=desc')
        );
    }

    public function test_an_unknown_sort_key_falls_back_to_the_default(): void
    {
        $this->actingAsUser();
        $this->book('Second', ['last_name' => 'Bronte']);
        $this->book('First', ['last_name' => 'Achebe']);

        $this->assertSame(
            ['First', 'Second'],
            $this->titles('/api/books?sort=havoc&direction=sideways')
        );
    }

    public function test_sorting_composes_with_search(): void
    {
        $this->actingAsUser();
        $this->book('Dune Messiah', ['page_count' => 300]);
        $this->book('Dune', ['page_count' => 800]);
        $this->book('Neuromancer', ['page_count' => 100]);

        $this->assertSame(
            ['Dune', 'Dune Messiah'],
            $this->titles('/api/books?search=Dune&sort=pages&direction=desc')
        );
    }

    public function test_sorting_composes_with_the_discarded_filter(): void
    {
        $this->actingAsUser();
        $kept = $this->book('Kept', ['page_count' => 100]);
        $gone = $this->book('Gone', ['page_count' => 500]);
        $gone->versions()->update(['is_discarded' => true]);

        $this->assertSame(['Kept'], $this->titles('/api/books?sort=pages&direction=desc'));
        $this->assertSame(
            ['Gone'],
            $this->titles('/api/books?sort=pages&direction=desc&discarded=only')
        );
        $this->assertSame(
            ['Gone', 'Kept'],
            $this->titles('/api/books?sort=pages&direction=desc&discarded=all')
        );

        $this->assertSame($kept->title, 'Kept');
    }

    public function test_pagination_does_not_repeat_or_drop_rows_when_the_sort_key_ties(): void
    {
        $this->actingAsUser();

        // Every book has the same page count, so the sort key alone cannot
        // order them and the PK tiebreak is the only thing keeping pages
        // disjoint.
        foreach (range(1, 6) as $n) {
            $this->book("Tied {$n}", ['page_count' => 250]);
        }

        $first = $this->titles('/api/books?sort=pages&limit=3&page=1');
        $second = $this->titles('/api/books?sort=pages&limit=3&page=2');

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertEmpty(array_intersect($first, $second));
        $this->assertEqualsCanonicalizing(
            ['Tied 1', 'Tied 2', 'Tied 3', 'Tied 4', 'Tied 5', 'Tied 6'],
            array_merge($first, $second)
        );
    }

    public function test_search_treats_like_wildcards_as_literal_characters(): void
    {
        $this->actingAsUser();
        $this->book('100% Cotton');
        $this->book('Neuromancer');

        $this->assertSame(['100% Cotton'], $this->titles('/api/books?search=100%25'));
        // A bare `%` used to match every title.
        $this->assertSame([], $this->titles('/api/books?search=%25%25%25'));
    }
}
