<?php

namespace Tests\Feature\UserScoping;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BooksScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_does_not_expose_other_users_read_instances(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        ReadInstance::factory()->forUser($userA)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
        ]);

        $this->actingAsUser($userB);

        $response = $this->getJson('/api/books');

        $response->assertOk();
        $payload = $response->json('books');
        $this->assertNotEmpty($payload, 'global books list should still include the book');

        $entry = collect($payload)->firstWhere('book.book_id', $book->book_id);
        $this->assertNotNull($entry);
        $this->assertSame([], $entry['readInstances'], 'user B must not see user A reads');
    }

    public function test_show_book_returns_only_authenticated_users_read_instances(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        ReadInstance::factory()->forUser($userA)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
        ]);
        $userBRead = ReadInstance::factory()->forUser($userB)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
        ]);

        $this->actingAsUser($userB);

        $response = $this->getJson("/api/book/{$book->slug}");

        $response->assertOk();
        $reads = collect($response->json('readInstances'));
        $this->assertCount(1, $reads);
        $this->assertSame($userB->user_id, $reads->first()['user_id']);
        $this->assertSame($userBRead->date_read->format('Y-m-d'), $reads->first()['date_read']);
    }

    public function test_completed_years_excludes_other_users_years(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        ReadInstance::factory()->forUser($userA)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2021-06-15',
        ]);
        ReadInstance::factory()->forUser($userB)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2024-03-10',
        ]);

        $this->actingAsUser($userB);

        $response = $this->getJson('/api/completed/years');

        $response->assertOk()->assertExactJson([2024]);
    }

    public function test_books_by_year_excludes_other_users_reads(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $versionA = Version::factory()->for($bookA, 'book')->create();
        $versionB = Version::factory()->for($bookB, 'book')->create();

        ReadInstance::factory()->forUser($userA)->create([
            'book_id' => $bookA->book_id,
            'version_id' => $versionA->version_id,
            'date_read' => '2024-04-01',
        ]);
        ReadInstance::factory()->forUser($userB)->create([
            'book_id' => $bookB->book_id,
            'version_id' => $versionB->version_id,
            'date_read' => '2024-05-01',
        ]);

        $this->actingAsUser($userB);

        $response = $this->getJson('/api/completed/2024');

        $response->assertOk();
        $bookIds = collect($response->json())->pluck('book.book_id')->all();
        $this->assertSame([$bookB->book_id], $bookIds);
    }

    public function test_add_read_instance_overrides_user_id_with_auth_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $this->actingAsUser($userB);

        $response = $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'user_id' => $userA->user_id,
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => '2024-07-04',
                'rating' => 4,
            ],
        ]);

        $response->assertOk();
        $this->assertSame($userB->user_id, $response->json('user_id'));
        $this->assertDatabaseHas('read_instances', [
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'user_id' => $userB->user_id,
        ]);
        $this->assertDatabaseMissing('read_instances', [
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'user_id' => $userA->user_id,
        ]);
    }
}
