<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /books?read=` — the library's "Unread / Read" control.
 */
class ReadStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private function book(string $title, ?int $readByUserId = null): Book
    {
        $book = Book::factory()->create(['title' => $title]);
        Author::factory()->create()->books()->attach($book->book_id, ['author_ordinal' => 1]);
        $version = Version::factory()->for($book, 'book')->create();

        if ($readByUserId !== null) {
            ReadInstance::factory()->create([
                'user_id' => $readByUserId,
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => '2024-01-01',
                'rating' => 4,
            ]);
        }

        return $book;
    }

    private function titlesFrom($response): array
    {
        return collect($response->json('books'))->pluck('book.title')->sort()->values()->all();
    }

    public function test_read_returns_only_books_with_a_read_instance(): void
    {
        $user = $this->actingAsUser();
        $this->book('Finished', $user->user_id);
        $this->book('Waiting');

        $response = $this->getJson('/api/books?read=read');

        $response->assertOk();
        $this->assertSame(['Finished'], $this->titlesFrom($response));
        $this->assertSame(1, $response->json('pagination.total'));
    }

    public function test_unread_returns_only_books_without_a_read_instance(): void
    {
        $user = $this->actingAsUser();
        $this->book('Finished', $user->user_id);
        $this->book('Waiting');

        $response = $this->getJson('/api/books?read=unread');

        $response->assertOk();
        $this->assertSame(['Waiting'], $this->titlesFrom($response));
    }

    public function test_another_users_read_does_not_count(): void
    {
        $other = User::factory()->create();
        $this->actingAsUser();
        $this->book('Theirs', $other->user_id);

        $this->assertSame(['Theirs'], $this->titlesFrom($this->getJson('/api/books?read=unread')));
        $this->assertSame([], $this->titlesFrom($this->getJson('/api/books?read=read')));
    }

    public function test_unrecognized_value_applies_no_filter(): void
    {
        $user = $this->actingAsUser();
        $this->book('Finished', $user->user_id);
        $this->book('Waiting');

        $response = $this->getJson('/api/books?read=whatever');

        $response->assertOk();
        $this->assertSame(['Finished', 'Waiting'], $this->titlesFrom($response));
    }

    public function test_read_filter_composes_with_search_and_sort(): void
    {
        $user = $this->actingAsUser();
        $this->book('Alpha Finished', $user->user_id);
        $this->book('Beta Finished', $user->user_id);
        $this->book('Alpha Waiting');

        $response = $this->getJson('/api/books?read=read&search=alpha&sort=title');

        $response->assertOk();
        $this->assertSame(['Alpha Finished'], $this->titlesFrom($response));
    }
}
