<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BooksCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_books_and_returns_structured_payload(): void
    {
        $this->actingAsUser();

        $books = Book::factory()->count(3)->create();
        foreach ($books as $book) {
            $author = Author::factory()->create();
            $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);
            Version::factory()->for($book, 'book')->create();
        }

        $response = $this->getJson('/api/books');

        $response->assertOk()->assertJsonStructure([
            'books' => [['book' => ['book_id', 'title', 'slug'], 'authors', 'versions', 'genres', 'readInstances']],
            'pagination' => ['total', 'perPage', 'currentPage', 'lastPage', 'from', 'to'],
        ]);
        $this->assertSame(3, $response->json('pagination.total'));
    }

    public function test_index_search_filters_by_title(): void
    {
        $this->actingAsUser();

        $needle = Book::factory()->create(['title' => 'The Phoenix Project', 'slug' => 'the-phoenix-project']);
        Author::factory()->create()->books()->attach($needle->book_id, ['author_ordinal' => 1]);

        $other = Book::factory()->create(['title' => 'Refactoring', 'slug' => 'refactoring']);
        Author::factory()->create()->books()->attach($other->book_id, ['author_ordinal' => 1]);

        $response = $this->getJson('/api/books?search=Phoenix');

        $response->assertOk();
        $titles = collect($response->json('books'))->pluck('book.title')->all();
        $this->assertSame(['The Phoenix Project'], $titles);
    }

    public function test_show_by_id_returns_book_with_relations(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $author = Author::factory()->create();
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);
        Version::factory()->for($book, 'book')->create();

        $response = $this->getJson("/api/books/{$book->book_id}");

        $response->assertOk()->assertJsonStructure([
            'book' => ['book_id', 'title', 'slug'],
            'authors', 'versions', 'genres', 'readInstances', 'authorRelatedBooks',
        ]);
        $this->assertSame($book->book_id, $response->json('book.book_id'));
    }

    public function test_show_by_slug_returns_same_book(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Dune', 'slug' => 'dune']);
        Author::factory()->create()->books()->attach($book->book_id, ['author_ordinal' => 1]);
        Version::factory()->for($book, 'book')->create();

        $response = $this->getJson('/api/book/dune');

        $response->assertOk();
        $this->assertSame($book->book_id, $response->json('book.book_id'));
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/book/does-not-exist')->assertNotFound();
    }

    public function test_store_creates_book_with_authors_versions_and_genres(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Hardcover']);

        $response = $this->postJson('/api/books', [
            'book' => [
                'book' => [
                    'title' => 'A New Hope',
                    'genres' => ['parsed' => ['Fantasy', 'Adventure']],
                ],
                'authors' => [
                    ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
                ],
                'versions' => [
                    ['format' => $format->format_id, 'page_count' => 320, 'nickname' => 'first edition', 'audio_runtime' => null],
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('books', ['title' => 'A New Hope', 'slug' => 'a-new-hope']);
        $book = Book::where('slug', 'a-new-hope')->firstOrFail();

        $this->assertDatabaseHas('authors', ['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->assertCount(1, $book->authors);
        $this->assertCount(1, $book->versions);
        $this->assertSame(320, $book->versions->first()->page_count);
        $this->assertCount(2, $book->genres);
        $this->assertEqualsCanonicalizing(['Fantasy', 'Adventure'], $book->genres->pluck('name')->all());
    }

    public function test_store_with_existing_slug_only_appends_a_new_version(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Audiobook']);
        $existing = Book::factory()->create(['title' => 'Recursion', 'slug' => Str::slug('Recursion')]);
        Version::factory()->for($existing, 'book')->create();

        $response = $this->postJson('/api/books', [
            'book' => [
                'book' => [
                    'title' => 'Recursion',
                    'genres' => ['parsed' => []],
                ],
                'authors' => [['first_name' => 'New', 'last_name' => 'Author']],
                'versions' => [
                    ['format' => $format->format_id, 'page_count' => 0, 'nickname' => 'audio', 'audio_runtime' => 540],
                ],
            ],
        ]);

        $response->assertOk();
        $existing->refresh();
        $this->assertCount(2, $existing->versions);
        $this->assertCount(0, $existing->authors, 'authors should not be re-attached on the existing-book branch');
        $this->assertDatabaseMissing('authors', ['first_name' => 'New', 'last_name' => 'Author']);
    }

    public function test_destroy_removes_book_versions_and_orphan_authors(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $orphanAuthor = Author::factory()->create();
        $book->authors()->attach($orphanAuthor->author_id, ['author_ordinal' => 1]);
        $version = Version::factory()->for($book, 'book')->create();

        $response = $this->deleteJson("/api/books/{$book->book_id}");

        $response->assertOk()->assertJsonStructure(['message', 'deleted_authors']);
        $this->assertDatabaseMissing('books', ['book_id' => $book->book_id]);
        $this->assertDatabaseMissing('versions', ['version_id' => $version->version_id]);
        $this->assertDatabaseMissing('authors', ['author_id' => $orphanAuthor->author_id]);
    }

    public function test_destroy_keeps_authors_who_have_other_books(): void
    {
        $this->actingAsUser();
        $sharedAuthor = Author::factory()->create();
        $bookToDelete = Book::factory()->create();
        $otherBook = Book::factory()->create();
        $bookToDelete->authors()->attach($sharedAuthor->author_id, ['author_ordinal' => 1]);
        $otherBook->authors()->attach($sharedAuthor->author_id, ['author_ordinal' => 1]);

        $this->deleteJson("/api/books/{$bookToDelete->book_id}")->assertOk();

        $this->assertDatabaseHas('authors', ['author_id' => $sharedAuthor->author_id]);
        $this->assertDatabaseHas('books', ['book_id' => $otherBook->book_id]);
    }
}
