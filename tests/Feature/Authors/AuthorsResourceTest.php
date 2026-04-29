<?php

namespace Tests\Feature\Authors;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorsResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_author_by_slug_returns_author_with_books(): void
    {
        $this->actingAsUser();
        $author = Author::factory()->create(['first_name' => 'N. K.', 'last_name' => 'Jemisin', 'slug' => 'nk-jemisin']);
        $book = Book::factory()->create();
        $author->books()->attach($book->book_id, ['author_ordinal' => 1]);

        $response = $this->getJson('/api/author/nk-jemisin');

        $response->assertOk()->assertJsonStructure([
            'author' => ['author_id', 'first_name', 'last_name', 'slug'],
            'books' => [['book' => ['book_id', 'title', 'slug'], 'authors', 'genres', 'versions', 'read_instances']],
        ]);
        $this->assertSame($author->author_id, $response->json('author.author_id'));
        $this->assertCount(1, $response->json('books'));
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/author/no-such-author')->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        Author::factory()->create(['slug' => 'someone']);
        $this->getJson('/api/author/someone')->assertUnauthorized();
    }
}
