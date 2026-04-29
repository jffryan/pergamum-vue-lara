<?php

namespace Tests\Feature\Genres;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenresResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_genres_with_book_counts(): void
    {
        $this->actingAsUser();
        $fantasy = Genre::factory()->create(['name' => 'Fantasy']);
        $mystery = Genre::factory()->create(['name' => 'Mystery']);

        $book = Book::factory()->create();
        $book->genres()->attach($fantasy->genre_id);

        $response = $this->getJson('/api/genres');

        $response->assertOk();
        $byName = collect($response->json())->keyBy('name');
        $this->assertSame(1, $byName['Fantasy']['books_count']);
        $this->assertSame(0, $byName['Mystery']['books_count']);
    }

    public function test_show_returns_paginated_books_in_genre(): void
    {
        $this->actingAsUser();
        $genre = Genre::factory()->create(['name' => 'Sci-Fi']);
        $book = Book::factory()->create();
        $author = Author::factory()->create();
        $book->genres()->attach($genre->genre_id);
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);
        Version::factory()->for($book, 'book')->create();

        $response = $this->getJson("/api/genres/{$genre->genre_id}");

        $response->assertOk()->assertJsonStructure([
            'genre' => ['genre_id', 'name'],
            'books' => [['book' => ['book_id', 'title', 'slug'], 'authors', 'versions', 'genres', 'readInstances']],
            'pagination' => ['total', 'perPage', 'currentPage', 'lastPage'],
        ]);
        $this->assertSame(1, $response->json('pagination.total'));
    }

    public function test_show_unknown_genre_returns_404(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/genres/9999999')->assertNotFound();
    }
}
