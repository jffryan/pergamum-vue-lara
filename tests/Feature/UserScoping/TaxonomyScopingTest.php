<?php

namespace Tests\Feature\UserScoping;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\Scopes\BelongsToCurrentUser;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The author and genre detail payloads used to eager-load `books.readInstances`
 * with no user predicate — the two surfaces that forgot the `auth()->id()`
 * every other reader remembered. `BelongsToCurrentUser` on `ReadInstance` is
 * what closes them; these tests are what keeps them closed.
 */
class TaxonomyScopingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Book, 1: User, 2: User}
     */
    private function bookReadByBothUsers(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        foreach ([$userA, $userB] as $user) {
            ReadInstance::factory()->forUser($user)->create([
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
            ]);
        }

        return [$book, $userA, $userB];
    }

    public function test_author_detail_returns_only_the_authenticated_users_reads(): void
    {
        [$book, , $userB] = $this->bookReadByBothUsers();

        $author = Author::factory()->create();
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);

        $this->actingAsUser($userB);

        $response = $this->getJson("/api/author/{$author->slug}");
        $response->assertOk();

        $reads = $response->json('books.0.read_instances');
        $this->assertCount(1, $reads, 'user B must see only their own read of the shared book');
        $this->assertSame($userB->user_id, $reads[0]['user_id']);
    }

    public function test_genre_detail_returns_only_the_authenticated_users_reads(): void
    {
        [$book, , $userB] = $this->bookReadByBothUsers();

        $genre = Genre::create(['name' => 'science fiction']);
        $book->genres()->attach($genre->genre_id);

        $this->actingAsUser($userB);

        $response = $this->getJson("/api/genres/{$genre->genre_id}");
        $response->assertOk();

        $reads = $response->json('books.0.readInstances');
        $this->assertCount(1, $reads, 'user B must see only their own read of the shared book');
        $this->assertSame($userB->user_id, $reads[0]['user_id']);
    }

    /**
     * The catalog stays shared: only the read history is per-user. A book both
     * accounts have read still appears once on each account's author page.
     */
    public function test_the_book_itself_is_still_visible_to_both_users(): void
    {
        [$book, $userA, $userB] = $this->bookReadByBothUsers();

        $author = Author::factory()->create();
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);

        foreach ([$userA, $userB] as $user) {
            $this->actingAsUser($user);
            $response = $this->getJson("/api/author/{$author->slug}");
            $response->assertOk();
            $response->assertJsonPath('books.0.book.book_id', $book->book_id);
        }
    }

    public function test_querying_read_instances_without_a_session_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scoped to the authenticated user');

        ReadInstance::count();
    }

    public function test_the_scope_can_be_lifted_deliberately(): void
    {
        [, $userA] = $this->bookReadByBothUsers();

        $this->actingAsUser($userA);

        $this->assertSame(1, ReadInstance::count());
        $this->assertSame(
            2,
            ReadInstance::withoutGlobalScope(BelongsToCurrentUser::class)->count()
        );
    }
}
