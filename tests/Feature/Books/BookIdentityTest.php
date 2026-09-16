<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Services\AuthorService;
use App\Support\BookCreator;
use App\Support\BookMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "same book?" rule and the slug a second same-title book lands on.
 *
 * Pinned by the merge that motivated both: Sylvia Plath's *Ariel* and José
 * Enrique Rodó's *Ariel* filed as one book because identity was the title
 * slug alone.
 */
class BookIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function bookBy(string $title, string $slug, array ...$authors): Book
    {
        $book = Book::factory()->create(['title' => $title, 'slug' => $slug]);
        foreach ($authors as $i => [$first, $last]) {
            $author = Author::factory()->create([
                'first_name' => $first,
                'last_name' => $last,
                'slug' => AuthorService::slugFor($first, $last),
            ]);
            $book->authors()->attach($author->author_id, ['author_ordinal' => $i + 1]);
        }

        return $book;
    }

    // ---------- BookMatcher ----------

    public function test_same_title_and_different_authors_is_not_the_same_book(): void
    {
        $this->bookBy('Ariel', 'ariel', ['Sylvia', 'Plath']);

        $this->assertNull(BookMatcher::find('Ariel', [
            ['first_name' => 'José Enrique', 'last_name' => 'Rodó'],
        ]));
    }

    public function test_same_title_and_one_shared_author_is_the_same_book(): void
    {
        $book = $this->bookBy('Profiles in Courage', 'profiles-in-courage', ['John F.', 'Kennedy']);

        // A later edition with a foreword adds an author, not a book.
        $found = BookMatcher::find('Profiles in Courage', [
            ['first_name' => 'John F.', 'last_name' => 'Kennedy'],
            ['first_name' => 'Allan', 'last_name' => 'Nevins'],
        ]);

        $this->assertSame($book->book_id, $found?->book_id);
    }

    public function test_matching_is_by_author_slug_not_spelling(): void
    {
        $book = $this->bookBy('Dune', 'dune', ['Frank', 'Herbert']);

        $found = BookMatcher::find('dune', [['first_name' => 'FRANK', 'last_name' => 'herbert']]);

        $this->assertSame($book->book_id, $found?->book_id);
    }

    public function test_the_right_same_title_book_is_picked_among_several(): void
    {
        $this->bookBy('Ariel', 'ariel', ['Sylvia', 'Plath']);
        $rodo = $this->bookBy('Ariel', 'ariel-rodo', ['José Enrique', 'Rodó'], ['Margaret Sayers', 'Peden']);

        $found = BookMatcher::find('Ariel', [['first_name' => 'Margaret Sayers', 'last_name' => 'Peden']]);

        $this->assertSame($rodo->book_id, $found?->book_id);
        $this->assertCount(2, BookMatcher::sameTitle('Ariel'));
    }

    public function test_a_title_that_merely_shares_a_slug_prefix_is_not_a_candidate(): void
    {
        $this->bookBy('Ariel', 'ariel', ['Sylvia', 'Plath']);
        $this->bookBy("Ariel's Gift", 'ariel-s-gift', ['Erica', 'Wagner']);

        $this->assertSame(['ariel'], BookMatcher::sameTitle('Ariel')->pluck('slug')->all());
    }

    public function test_an_authorless_candidate_never_matches(): void
    {
        $this->bookBy('Ariel', 'ariel');

        $this->assertNull(BookMatcher::find('Ariel', [['first_name' => 'Sylvia', 'last_name' => 'Plath']]));
        $this->assertNull(BookMatcher::find('Ariel', []));
    }

    // ---------- BookCreator slug rules ----------

    public function test_the_first_book_with_a_title_gets_the_bare_slug(): void
    {
        $book = BookCreator::create('Ariel', [['first_name' => 'Sylvia', 'last_name' => 'Plath']]);

        $this->assertSame('ariel', $book->slug);
    }

    public function test_a_second_book_with_the_title_is_filed_under_its_authors_surname(): void
    {
        $this->bookBy('Ariel', 'ariel', ['Sylvia', 'Plath']);

        $book = BookCreator::create('Ariel', [
            ['first_name' => 'José Enrique', 'last_name' => 'Rodó'],
            ['first_name' => 'Margaret Sayers', 'last_name' => 'Peden'],
        ]);

        $this->assertSame('ariel-rodo', $book->slug);
    }

    public function test_a_mononym_disambiguates_by_its_only_name(): void
    {
        $this->bookBy('Republic', 'republic', ['Some', 'One']);

        $book = BookCreator::create('Republic', [['first_name' => 'Plato', 'last_name' => '']]);

        $this->assertSame('republic-plato', $book->slug);
    }

    public function test_a_third_book_by_a_same_surname_author_gets_a_number(): void
    {
        $this->bookBy('Ariel', 'ariel', ['Sylvia', 'Plath']);
        $this->bookBy('Ariel', 'ariel-rodo', ['José Enrique', 'Rodó']);

        $book = BookCreator::create('Ariel', [['first_name' => 'Other', 'last_name' => 'Rodó']]);

        $this->assertSame('ariel-rodo-1', $book->slug);
    }

    public function test_without_authors_a_collision_falls_straight_to_a_number(): void
    {
        $this->bookBy('Ariel', 'ariel');

        $this->assertSame('ariel-1', BookCreator::create('Ariel')->slug);
    }

    public function test_rename_slug_ignores_the_books_own_row(): void
    {
        $book = $this->bookBy('Ariel', 'ariel', ['Sylvia', 'Plath']);

        $this->assertSame('ariel', BookCreator::slugFor('Ariel', [], $book));
    }
}
