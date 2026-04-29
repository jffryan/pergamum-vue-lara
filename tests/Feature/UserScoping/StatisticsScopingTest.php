<?php

namespace Tests\Feature\UserScoping;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_stats_only_count_authenticated_users_reads(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $versionA = Version::factory()->for($bookA, 'book')->create(['page_count' => 250]);
        $versionB = Version::factory()->for($bookB, 'book')->create(['page_count' => 400]);

        ReadInstance::factory()->forUser($userA)->create([
            'book_id' => $bookA->book_id,
            'version_id' => $versionA->version_id,
            'date_read' => '2023-01-15',
        ]);
        ReadInstance::factory()->forUser($userB)->create([
            'book_id' => $bookB->book_id,
            'version_id' => $versionB->version_id,
            'date_read' => '2024-08-22',
        ]);

        $this->actingAsUser($userB);

        $response = $this->getJson('/api/statistics');

        $response->assertOk();
        $this->assertSame(1, $response->json('total_books_read'));

        $byYear = $response->json('booksReadByYear');
        $this->assertCount(1, $byYear);
        $this->assertSame(2024, (int) $byYear[0]['year']);
        $this->assertSame(1, (int) $byYear[0]['total']);

        $pagesByYear = $response->json('totalPagesByYear');
        $this->assertCount(1, $pagesByYear);
        $this->assertSame(2024, $pagesByYear[0]['year']);
        $this->assertSame(400, $pagesByYear[0]['total']);
    }

    public function test_user_with_no_reads_sees_zeroed_stats(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        ReadInstance::factory()->forUser($userA)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2023-04-01',
        ]);

        $this->actingAsUser($userB);

        $response = $this->getJson('/api/statistics');

        $response->assertOk();
        $this->assertSame(0, $response->json('total_books_read'));
        $this->assertSame([], $response->json('booksReadByYear'));
        $this->assertSame([], $response->json('totalPagesByYear'));
    }
}
