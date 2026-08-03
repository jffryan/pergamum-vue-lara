<?php

namespace Tests\Feature\Statistics;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The metrics that didn't exist before the registry: totals that stopped
 * being derived in the view, the rating pair, and the audio/estimate trio.
 */
class UserMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function readBook(int $userId, array $versionAttributes, ?string $dateRead, ?int $rating = null): ReadInstance
    {
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create($versionAttributes);

        return ReadInstance::factory()->create([
            'user_id' => $userId,
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => $dateRead,
            'rating' => $rating,
        ]);
    }

    public function test_total_reads_counts_undated_reads_that_reads_by_year_cannot(): void
    {
        $user = $this->actingAsUser();

        $this->readBook($user->user_id, ['page_count' => 100], '2024-01-01');
        $this->readBook($user->user_id, ['page_count' => 100], null);

        $response = $this->getJson('/api/statistics')->assertOk();

        // The old dashboard summed readsByYear for this number and lost the
        // undated read every time.
        $this->assertSame(2, $response->json('metrics.totalReads'));
        $this->assertSame([['year' => 2024, 'total' => 1]], $response->json('metrics.readsByYear'));
    }

    public function test_unique_books_read_by_year_collapses_re_reads(): void
    {
        $user = $this->actingAsUser();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create(['page_count' => 100]);
        foreach (['2024-02-01', '2024-11-11'] as $date) {
            ReadInstance::factory()->forUser($user)->create([
                'book_id' => $book->book_id, 'version_id' => $version->version_id, 'date_read' => $date,
            ]);
        }
        $this->readBook($user->user_id, ['page_count' => 100], '2024-06-06');

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertSame([['year' => 2024, 'total' => 3]], $response->json('metrics.readsByYear'));
        $this->assertSame([['year' => 2024, 'total' => 2]], $response->json('metrics.uniqueBooksReadByYear'));
    }

    public function test_ratings_are_returned_on_the_display_scale(): void
    {
        $user = $this->actingAsUser();

        // The mutator doubles on the way in: 4 and 5 are stored as 8 and 10.
        $this->readBook($user->user_id, ['page_count' => 100], '2024-01-01', 4);
        $this->readBook($user->user_id, ['page_count' => 100], '2024-02-01', 5);
        $this->readBook($user->user_id, ['page_count' => 100], '2024-03-01', null);

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertSame(4.5, $response->json('metrics.averageRating'));
        $this->assertSame([
            ['rating' => 5, 'total' => 1],
            ['rating' => 4, 'total' => 1],
        ], $response->json('metrics.ratingDistribution'));
    }

    public function test_average_rating_is_null_when_nothing_is_rated(): void
    {
        $user = $this->actingAsUser();

        $this->readBook($user->user_id, ['page_count' => 100], '2024-01-01', null);

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertNull($response->json('metrics.averageRating'));
        $this->assertSame([], $response->json('metrics.ratingDistribution'));
    }

    public function test_audio_runtime_is_reported_in_minutes_and_kept_out_of_the_page_count(): void
    {
        $user = $this->actingAsUser();

        $this->readBook($user->user_id, ['page_count' => 300, 'audio_runtime' => null], '2024-05-01');
        $this->readBook($user->user_id, ['page_count' => 0, 'audio_runtime' => 600], '2024-07-01');

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertSame([['year' => 2024, 'total' => 300]], $response->json('metrics.pagesReadByYear'));
        $this->assertSame([['year' => 2024, 'total' => 600]], $response->json('metrics.audioRuntimeByYear'));
    }

    /**
     * The estimate is reproducible and carries its own provenance, so a widget
     * can render the caveat from data instead of hardcoding it.
     */
    public function test_estimated_total_pages_combines_both_series_and_declares_itself(): void
    {
        $user = $this->actingAsUser();

        config(['statistics.estimates.pagesPerAudioMinute' => 0.5]);

        $this->readBook($user->user_id, ['page_count' => 300, 'audio_runtime' => null], '2024-05-01');
        $this->readBook($user->user_id, ['page_count' => 0, 'audio_runtime' => 600], '2024-07-01');
        $this->readBook($user->user_id, ['page_count' => 0, 'audio_runtime' => 120], '2023-03-01');

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertSame([
            ['year' => 2024, 'total' => 600],   // 300 pages + 600 minutes × 0.5
            ['year' => 2023, 'total' => 60],    // 120 minutes × 0.5
        ], $response->json('metrics.estimatedTotalPagesByYear'));

        $this->assertSame([
            'pagesPerAudioMinute' => 0.5,
            'from' => ['pagesReadByYear', 'audioRuntimeByYear'],
            'converted' => 'audioRuntimeByYear',
        ], $response->json('meta.estimated.estimatedTotalPagesByYear'));
        $this->assertSame([], $response->json('meta.estimated.pagesReadByYear') ?? []);
    }

    public function test_estimate_can_be_requested_without_its_measured_inputs(): void
    {
        $user = $this->actingAsUser();

        config(['statistics.estimates.pagesPerAudioMinute' => 0.5]);
        $this->readBook($user->user_id, ['page_count' => 0, 'audio_runtime' => 100], '2024-01-01');

        $response = $this->getJson('/api/statistics?metrics=estimatedTotalPagesByYear')->assertOk();

        $this->assertSame(['estimatedTotalPagesByYear'], array_keys($response->json('metrics')));
        $this->assertSame([['year' => 2024, 'total' => 50]], $response->json('metrics.estimatedTotalPagesByYear'));
        $this->assertArrayHasKey('estimatedTotalPagesByYear', $response->json('meta.estimated'));
    }
}
