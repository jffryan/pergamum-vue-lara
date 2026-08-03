<?php

namespace Tests\Feature\Statistics;

use App\Models\Book;
use App\Models\BookList;
use App\Models\Genre;
use App\Models\ListItem;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function addToList(BookList $list, Version $version, int $ordinal = 0): ListItem
    {
        return ListItem::factory()->create([
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
            'ordinal' => $ordinal,
        ]);
    }

    public function test_empty_list_reports_zeroes(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        $response = $this->getJson("/api/statistics/list/{$list->list_id}")->assertOk();

        $this->assertSame('list', $response->json('scope.type'));
        $this->assertSame($list->list_id, $response->json('scope.id'));
        $this->assertSame(0, $response->json('metrics.totalItems'));
        $this->assertSame(0, $response->json('metrics.completedCount'));
        $this->assertSame(0, $response->json('metrics.completedPercent'));
        $this->assertSame(0, $response->json('metrics.totalPages'));
        $this->assertSame([], $response->json('metrics.genreBreakdown'));
        $this->assertNull($response->json('metrics.averageRating'));
    }

    /**
     * The works-versus-volume rule, both sides in one test: two copies of one
     * novel are one book on the list and two stacks of pages on the shelf.
     * Asserting only one side would read as an oversight.
     */
    public function test_books_dedupe_by_work_while_pages_count_every_copy(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        $book = Book::factory()->create();
        $paperback = Version::factory()->for($book, 'book')->create(['page_count' => 300]);
        $hardback = Version::factory()->for($book, 'book')->create(['page_count' => 340]);

        $this->addToList($list, $paperback, 0);
        $this->addToList($list, $hardback, 1);

        $response = $this->getJson("/api/statistics/list/{$list->list_id}")->assertOk();

        $this->assertSame(1, $response->json('metrics.totalItems'));
        $this->assertSame(640, $response->json('metrics.totalPages'));
    }

    public function test_completion_counts_a_read_of_any_copy(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        $read = Book::factory()->create();
        $listed = Version::factory()->for($read, 'book')->create(['page_count' => 200]);
        $other = Version::factory()->for($read, 'book')->create(['page_count' => 0]);
        $this->addToList($list, $listed, 0);

        // Recorded against the copy that isn't on the list.
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $read->book_id,
            'version_id' => $other->version_id,
            'date_read' => '2024-01-01',
        ]);

        $unread = Book::factory()->create();
        $this->addToList($list, Version::factory()->for($unread, 'book')->create(['page_count' => 100]), 1);

        $response = $this->getJson("/api/statistics/list/{$list->list_id}")->assertOk();

        $this->assertSame(2, $response->json('metrics.totalItems'));
        $this->assertSame(1, $response->json('metrics.completedCount'));
        $this->assertSame(50, $response->json('metrics.completedPercent'));
    }

    public function test_average_rating_covers_the_users_reads_of_listed_books(): void
    {
        $user = $this->actingAsUser();
        $other = User::factory()->create();
        $list = BookList::factory()->forUser($user)->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create(['page_count' => 100]);
        $this->addToList($list, $version, 0);

        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id, 'version_id' => $version->version_id,
            'date_read' => '2024-01-01', 'rating' => 4,
        ]);
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id, 'version_id' => $version->version_id,
            'date_read' => '2025-01-01', 'rating' => 3,
        ]);
        // Someone else's opinion of the same book stays out of it.
        ReadInstance::factory()->forUser($other)->create([
            'book_id' => $book->book_id, 'version_id' => $version->version_id,
            'date_read' => '2024-06-01', 'rating' => 1,
        ]);

        $response = $this->getJson("/api/statistics/list/{$list->list_id}")->assertOk();

        $this->assertSame(3.5, $response->json('metrics.averageRating'));
    }

    public function test_genre_breakdown_counts_books_not_copies(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        $fiction = Genre::factory()->create(['name' => 'fiction']);
        $history = Genre::factory()->create(['name' => 'history']);

        $novel = Book::factory()->create();
        $novel->genres()->attach($fiction->genre_id);
        $this->addToList($list, Version::factory()->for($novel, 'book')->create(['page_count' => 100]), 0);
        $this->addToList($list, Version::factory()->for($novel, 'book')->create(['page_count' => 110]), 1);

        $second = Book::factory()->create();
        $second->genres()->attach([$fiction->genre_id, $history->genre_id]);
        $this->addToList($list, Version::factory()->for($second, 'book')->create(['page_count' => 200]), 2);

        $response = $this->getJson("/api/statistics/list/{$list->list_id}")->assertOk();

        $this->assertSame([
            ['name' => 'fiction', 'count' => 2],
            ['name' => 'history', 'count' => 1],
        ], $response->json('metrics.genreBreakdown'));
    }

    public function test_another_users_list_is_forbidden(): void
    {
        $this->actingAsUser();
        $theirs = BookList::factory()->create();

        $this->getJson("/api/statistics/list/{$theirs->list_id}")->assertForbidden();
    }

    public function test_unknown_list_is_not_found(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/statistics/list/999999')->assertNotFound();
    }

    public function test_user_only_metrics_are_rejected_on_a_list(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        $this->getJson("/api/statistics/list/{$list->list_id}?metrics=totalBooks")
            ->assertStatus(422);
    }
}
