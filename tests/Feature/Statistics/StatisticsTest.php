<?php

namespace Tests\Feature\Statistics;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_dataset_returns_zeroed_payload(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/statistics');

        $response->assertOk()->assertJsonStructure([
            'total_books',
            'total_books_read',
            'booksReadByYear',
            'totalPagesByYear',
            'percentageOfBooksRead',
            'newestBooks',
        ]);
        $this->assertSame(0, $response->json('total_books'));
        $this->assertSame(0, $response->json('total_books_read'));
        $this->assertSame(0, $response->json('percentageOfBooksRead'));
        $this->assertSame([], $response->json('booksReadByYear'));
        $this->assertSame([], $response->json('totalPagesByYear'));
        $this->assertSame([], $response->json('newestBooks'));
    }

    public function test_aggregates_reads_across_years_and_orders_desc(): void
    {
        $user = $this->actingAsUser();

        // 2024: two books (300 + 450 pages)
        $book2024a = Book::factory()->create();
        $book2024b = Book::factory()->create();
        $v2024a = Version::factory()->for($book2024a, 'book')->create(['page_count' => 300]);
        $v2024b = Version::factory()->for($book2024b, 'book')->create(['page_count' => 450]);
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book2024a->book_id, 'version_id' => $v2024a->version_id, 'date_read' => '2024-03-10',
        ]);
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book2024b->book_id, 'version_id' => $v2024b->version_id, 'date_read' => '2024-09-22',
        ]);

        // 2025: one book (200 pages)
        $book2025 = Book::factory()->create();
        $v2025 = Version::factory()->for($book2025, 'book')->create(['page_count' => 200]);
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book2025->book_id, 'version_id' => $v2025->version_id, 'date_read' => '2025-02-01',
        ]);

        $response = $this->getJson('/api/statistics')->assertOk();

        $byYear = collect($response->json('booksReadByYear'));
        $this->assertSame([2025, 2024], $byYear->pluck('year')->map(fn ($y) => (int) $y)->all());
        $this->assertSame([1, 2], $byYear->pluck('total')->map(fn ($t) => (int) $t)->all());

        $pages = $response->json('totalPagesByYear');
        $this->assertSame([
            ['year' => 2025, 'total' => 200],
            ['year' => 2024, 'total' => 750],
        ], $pages);
    }

    public function test_null_date_read_is_excluded_from_year_aggregations(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create(['page_count' => 100]);

        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id, 'version_id' => $version->version_id, 'date_read' => '2024-06-01',
        ]);
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id, 'version_id' => $version->version_id, 'date_read' => null,
        ]);

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertCount(1, $response->json('booksReadByYear'));
        $this->assertSame(1, (int) $response->json('booksReadByYear.0.total'));
        $this->assertSame([['year' => 2024, 'total' => 100]], $response->json('totalPagesByYear'));
    }

    public function test_percentage_of_books_read_uses_global_book_count(): void
    {
        $user = $this->actingAsUser();

        $read = Book::factory()->create();
        Book::factory()->count(3)->create();
        $version = Version::factory()->for($read, 'book')->create(['page_count' => 100]);
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $read->book_id, 'version_id' => $version->version_id, 'date_read' => '2024-01-01',
        ]);

        // ReadInstanceFactory::definition() eagerly creates a Version → Book even when overridden,
        // so total_books reflects the true row count rather than the four we explicitly created.
        $totalBooks = Book::count();
        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertSame($totalBooks, $response->json('total_books'));
        $this->assertSame(1, $response->json('total_books_read'));
        $expectedPct = round((1 / $totalBooks) * 100, 2);
        $this->assertEquals($expectedPct, $response->json('percentageOfBooksRead'));
    }

    public function test_newest_books_returns_five_most_recent_globally(): void
    {
        $this->actingAsUser();

        $books = [];
        for ($i = 0; $i < 7; $i++) {
            $books[] = Book::factory()->create([
                'created_at' => now()->subDays(7 - $i),
            ]);
        }

        $response = $this->getJson('/api/statistics')->assertOk();

        $newestIds = collect($response->json('newestBooks'))->pluck('book_id')->all();
        $this->assertCount(5, $newestIds);
        $expected = collect(array_slice($books, 2))->reverse()->pluck('book_id')->values()->all();
        $this->assertSame($expected, $newestIds);
    }
}
