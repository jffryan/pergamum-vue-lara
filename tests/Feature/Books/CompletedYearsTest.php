<?php

namespace Tests\Feature\Books;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompletedYearsTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_years_returns_distinct_years_descending(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        foreach (['2022-04-01', '2022-09-15', '2024-01-30', '2023-06-10'] as $date) {
            ReadInstance::factory()->forUser($user)->create([
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => $date,
            ]);
        }

        $response = $this->getJson('/api/completed/years');

        $response->assertOk()->assertExactJson([2024, 2023, 2022]);
    }

    public function test_completed_years_ignores_null_date_read(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => null,
        ]);

        $this->getJson('/api/completed/years')->assertOk()->assertExactJson([]);
    }

    public function test_books_by_year_returns_books_with_reads_in_that_year(): void
    {
        $user = $this->actingAsUser();

        $bookIn = Book::factory()->create(['title' => 'Read in 2024']);
        $versionIn = Version::factory()->for($bookIn, 'book')->create();
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $bookIn->book_id,
            'version_id' => $versionIn->version_id,
            'date_read' => '2024-07-04',
        ]);

        $bookOut = Book::factory()->create(['title' => 'Read in 2023']);
        $versionOut = Version::factory()->for($bookOut, 'book')->create();
        ReadInstance::factory()->forUser($user)->create([
            'book_id' => $bookOut->book_id,
            'version_id' => $versionOut->version_id,
            'date_read' => '2023-12-30',
        ]);

        $response = $this->getJson('/api/completed/2024');

        $response->assertOk();
        $ids = collect($response->json())->pluck('book.book_id')->all();
        $this->assertSame([$bookIn->book_id], $ids);
    }

    public function test_books_by_year_returns_empty_when_no_reads_match(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/completed/1999')->assertOk()->assertExactJson([]);
    }
}
