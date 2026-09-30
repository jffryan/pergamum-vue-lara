<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
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
        $returnedIds = collect($response->json('books'))->pluck('book.book_id')->all();
        $this->assertEqualsCanonicalizing($books->pluck('book_id')->all(), $returnedIds);
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

    private function renamePayload(string $title): array
    {
        return [
            'book' => ['title' => $title],
            'authors' => [],
            'genres' => [],
            'readInstances' => [],
            'versions' => [],
        ];
    }

    /**
     * A disambiguated slug (`ariel-rodo`) must survive a cosmetic retitle —
     * re-slugging from the title alone would land it back on the `ariel`
     * another book holds.
     */
    public function test_update_keeps_the_slug_when_the_title_slugs_the_same(): void
    {
        $this->actingAsUser();
        Book::factory()->create(['title' => 'Ariel', 'slug' => 'ariel']);
        $rodo = Book::factory()->create(['title' => 'Ariel', 'slug' => 'ariel-rodo']);

        $this->putJson("/api/books/{$rodo->book_id}", $this->renamePayload('ARIEL'))->assertOk();

        $this->assertDatabaseHas('books', ['book_id' => $rodo->book_id, 'title' => 'ARIEL', 'slug' => 'ariel-rodo']);
    }

    public function test_update_reslugs_a_real_retitle_and_dodges_a_collision(): void
    {
        $this->actingAsUser();
        Book::factory()->create(['title' => 'The Bell Jar', 'slug' => 'the-bell-jar']);
        $book = Book::factory()->withAuthors()->create(['title' => 'Ariel', 'slug' => 'ariel']);
        $surname = Str::slug($book->authors->first()->last_name);

        $this->putJson("/api/books/{$book->book_id}", $this->renamePayload('The Bell Jar'))->assertOk();

        $this->assertDatabaseHas('books', ['book_id' => $book->book_id, 'slug' => "the-bell-jar-{$surname}"]);
        $this->assertDatabaseHas('books', ['title' => 'The Bell Jar', 'slug' => 'the-bell-jar']);
    }
}
