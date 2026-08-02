<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscardedBooksFilterTest extends TestCase
{
    use RefreshDatabase;

    private function bookWithVersions(string $title, array $discardFlags): Book
    {
        $book = Book::factory()->create(['title' => $title]);
        Author::factory()->create()->books()->attach($book->book_id, ['author_ordinal' => 1]);

        foreach ($discardFlags as $isDiscarded) {
            $factory = Version::factory()->for($book, 'book');
            ($isDiscarded ? $factory->discarded() : $factory)->create();
        }

        return $book;
    }

    private function titlesFrom($response): array
    {
        return collect($response->json('books'))->pluck('book.title')->all();
    }

    public function test_index_hides_books_whose_every_version_is_discarded(): void
    {
        $this->actingAsUser();
        $this->bookWithVersions('Kept', [false]);
        $this->bookWithVersions('Gone', [true]);

        $response = $this->getJson('/api/books');

        $response->assertOk();
        $this->assertSame(['Kept'], $this->titlesFrom($response));
    }

    public function test_index_keeps_a_book_with_one_discarded_and_one_remaining_copy(): void
    {
        $this->actingAsUser();
        $this->bookWithVersions('Partly Gone', [true, false]);

        $response = $this->getJson('/api/books');

        $response->assertOk();
        $this->assertSame(['Partly Gone'], $this->titlesFrom($response));
    }

    public function test_index_keeps_books_with_no_versions_at_all(): void
    {
        $this->actingAsUser();
        $this->bookWithVersions('Versionless', []);

        $response = $this->getJson('/api/books');

        $response->assertOk();
        $this->assertSame(['Versionless'], $this->titlesFrom($response));
    }

    public function test_discarded_only_returns_just_the_fully_discarded_books(): void
    {
        $this->actingAsUser();
        $this->bookWithVersions('Kept', [false]);
        $this->bookWithVersions('Partly Gone', [true, false]);
        $this->bookWithVersions('Gone', [true]);
        $this->bookWithVersions('Versionless', []);

        $response = $this->getJson('/api/books?discarded=only');

        $response->assertOk();
        $this->assertSame(['Gone'], $this->titlesFrom($response));
    }

    public function test_discarded_all_returns_everything(): void
    {
        $this->actingAsUser();
        $this->bookWithVersions('Kept', [false]);
        $this->bookWithVersions('Gone', [true]);

        $response = $this->getJson('/api/books?discarded=all');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['Kept', 'Gone'], $this->titlesFrom($response));
    }

    public function test_search_obeys_the_same_shelf_filter(): void
    {
        $this->actingAsUser();
        $this->bookWithVersions('The Phoenix Project', [true]);

        $this->assertSame([], $this->titlesFrom($this->getJson('/api/books?search=Phoenix')));
        $this->assertSame(
            ['The Phoenix Project'],
            $this->titlesFrom($this->getJson('/api/books?search=Phoenix&discarded=only'))
        );
    }
}
