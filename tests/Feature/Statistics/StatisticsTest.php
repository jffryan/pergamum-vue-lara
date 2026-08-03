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
            'scope' => ['type', 'id'],
            'metrics' => [
                'totalBooks',
                'totalBooksRead',
                'readsByYear',
                'pagesReadByYear',
                'percentageOfBooksRead',
                'newestBooks',
            ],
            'meta' => ['catalogWide', 'shelfScoped', 'estimated', 'failed'],
        ]);
        $this->assertSame('user', $response->json('scope.type'));
        $this->assertSame(0, $response->json('metrics.totalBooks'));
        $this->assertSame(0, $response->json('metrics.totalBooksRead'));
        $this->assertEquals(0, $response->json('metrics.percentageOfBooksRead'));
        $this->assertSame([], $response->json('metrics.readsByYear'));
        $this->assertSame([], $response->json('metrics.pagesReadByYear'));
        $this->assertSame([], $response->json('metrics.newestBooks'));
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

        $this->assertSame([
            ['year' => 2025, 'total' => 1],
            ['year' => 2024, 'total' => 2],
        ], $response->json('metrics.readsByYear'));

        $this->assertSame([
            ['year' => 2025, 'total' => 200],
            ['year' => 2024, 'total' => 750],
        ], $response->json('metrics.pagesReadByYear'));
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

        $this->assertSame([['year' => 2024, 'total' => 1]], $response->json('metrics.readsByYear'));
        $this->assertSame([['year' => 2024, 'total' => 100]], $response->json('metrics.pagesReadByYear'));
    }

    public function test_reads_by_year_counts_re_reads_separately(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create(['page_count' => 100]);

        foreach (['2024-01-04', '2024-08-19'] as $date) {
            ReadInstance::factory()->forUser($user)->create([
                'book_id' => $book->book_id, 'version_id' => $version->version_id, 'date_read' => $date,
            ]);
        }

        $response = $this->getJson('/api/statistics')->assertOk();

        // The same book twice: two reads, one book, 200 pages of reading.
        $this->assertSame([['year' => 2024, 'total' => 2]], $response->json('metrics.readsByYear'));
        $this->assertSame(1, $response->json('metrics.totalBooksRead'));
        $this->assertSame([['year' => 2024, 'total' => 200]], $response->json('metrics.pagesReadByYear'));
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

        $response = $this->getJson('/api/statistics')->assertOk();

        // Exactly the four books created above — the factory no longer persists
        // a stray Version → Book behind the book_id / version_id overrides.
        $this->assertSame(4, $response->json('metrics.totalBooks'));
        $this->assertSame(1, $response->json('metrics.totalBooksRead'));
        $this->assertEquals(25.0, $response->json('metrics.percentageOfBooksRead'));

        // The catalogue-wide denominator is declared, not silently mixed in.
        $this->assertContains('totalBooks', $response->json('meta.catalogWide'));
        $this->assertNotContains('totalBooksRead', $response->json('meta.catalogWide'));
    }

    /**
     * The guard on the constraint that forces `totalBooks` catalogue-wide: the
     * numerator counts discarded books, so shelf-scoping the denominator "for
     * consistency" would let this exceed 100.
     */
    public function test_percentage_cannot_exceed_one_hundred_when_books_are_discarded(): void
    {
        $user = $this->actingAsUser();

        foreach (range(1, 4) as $i) {
            $book = Book::factory()->create();
            $factory = Version::factory()->for($book, 'book');
            $version = ($i <= 2 ? $factory->discarded() : $factory)->create(['page_count' => 100]);

            ReadInstance::factory()->forUser($user)->create([
                'book_id' => $book->book_id, 'version_id' => $version->version_id, 'date_read' => '2024-05-05',
            ]);
        }

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertSame(4, $response->json('metrics.totalBooks'));
        $this->assertSame(4, $response->json('metrics.totalBooksRead'));
        $this->assertEquals(100.0, $response->json('metrics.percentageOfBooksRead'));
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

        $newestIds = collect($response->json('metrics.newestBooks'))->pluck('book_id')->all();
        $this->assertCount(5, $newestIds);
        $expected = collect(array_slice($books, 2))->reverse()->pluck('book_id')->values()->all();
        $this->assertSame($expected, $newestIds);
    }

    /**
     * The two axes at once: a book whose every copy is gone leaves the shelf
     * but stays in the catalogue, and reading it still happened.
     */
    public function test_newest_books_excludes_fully_discarded_books(): void
    {
        $user = $this->actingAsUser();

        $kept = Book::factory()->create(['created_at' => now()->subDay()]);
        Version::factory()->for($kept, 'book')->create();

        $gone = Book::factory()->create(['created_at' => now()]);
        $goneVersion = Version::factory()->for($gone, 'book')->discarded()->create(['page_count' => 320]);
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $gone->book_id, 'version_id' => $goneVersion->version_id, 'date_read' => '2024-02-02',
        ]);

        $response = $this->getJson('/api/statistics')->assertOk();

        $newestIds = collect($response->json('metrics.newestBooks'))->pluck('book_id')->all();
        $this->assertSame([$kept->book_id], $newestIds);
        $this->assertSame(2, $response->json('metrics.totalBooks'));
        $this->assertSame([['year' => 2024, 'total' => 1]], $response->json('metrics.readsByYear'));
        $this->assertSame([['year' => 2024, 'total' => 320]], $response->json('metrics.pagesReadByYear'));

        $this->assertContains('newestBooks', $response->json('meta.shelfScoped'));
        $this->assertNotContains('totalBooks', $response->json('meta.shelfScoped'));
    }

    /**
     * `ReadInstance::booted()` blocks new mismatches, but rows that predate it
     * would otherwise contribute another book's page count.
     */
    public function test_pages_ignore_read_instances_whose_version_belongs_to_another_book(): void
    {
        $user = $this->actingAsUser();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create(['page_count' => 150]);

        $otherBook = Book::factory()->create();
        $otherVersion = Version::factory()->for($otherBook, 'book')->create(['page_count' => 900]);

        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id, 'version_id' => $version->version_id, 'date_read' => '2024-04-04',
        ]);

        // Straight to the DB — the model guard exists precisely to stop this.
        ReadInstance::query()->insert([
            'user_id' => $user->user_id,
            'book_id' => $book->book_id,
            'version_id' => $otherVersion->version_id,
            'date_read' => '2024-04-05',
            'rating' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/statistics')->assertOk();

        $this->assertSame([['year' => 2024, 'total' => 150]], $response->json('metrics.pagesReadByYear'));
        $this->assertSame([['year' => 2024, 'total' => 2]], $response->json('metrics.readsByYear'));
    }
}
