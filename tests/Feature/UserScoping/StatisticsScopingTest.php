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
        $this->assertSame(1, $response->json('metrics.totalBooksRead'));
        $this->assertSame([['year' => 2024, 'total' => 1]], $response->json('metrics.readsByYear'));
        $this->assertSame([['year' => 2024, 'total' => 400]], $response->json('metrics.pagesReadByYear'));
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
        $this->assertSame(0, $response->json('metrics.totalBooksRead'));
        $this->assertSame([], $response->json('metrics.readsByYear'));
        $this->assertSame([], $response->json('metrics.pagesReadByYear'));
    }
}
