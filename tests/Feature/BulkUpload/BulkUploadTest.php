<?php

namespace Tests\Feature\BulkUpload;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private function csvUpload(array $rows, string $filename = 'books.csv'): UploadedFile
    {
        $header = ['Title', 'Author_FNAME', 'Author_LNAME', 'Version_Format', 'Version_PageCount', 'Date_Read', 'Rating', 'Genres'];
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    public function test_happy_path_imports_book_author_genres_version_and_read_instance(): void
    {
        $user = $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);

        $file = $this->csvUpload([
            ['The Hobbit', 'J. R. R.', 'Tolkien', 'Hardcover', '310', '6/15/2024', '5', 'Fantasy, Adventure'],
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertOk();
        $this->assertSame(['total' => 1, 'succeeded' => 1, 'skipped' => 0, 'failed' => 0], $response->json('summary'));

        $book = Book::where('slug', 'the-hobbit')->firstOrFail();
        $this->assertCount(1, $book->authors);
        $this->assertCount(2, $book->genres);
        $this->assertCount(1, $book->versions);
        $this->assertSame(310, $book->versions->first()->page_count);
        $this->assertDatabaseHas('read_instances', [
            'book_id' => $book->book_id,
            'user_id' => $user->user_id,
            'date_read' => '2024-06-15',
            'rating' => 10, // doubled by ReadInstance::setRatingAttribute
        ]);
    }

    public function test_row_without_date_read_does_not_create_read_instance(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Paperback']);

        $file = $this->csvUpload([
            ['Untouched', 'Ada', 'Lovelace', 'Paperback', '120', '', '', ''],
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame(1, $response->json('summary.succeeded'));

        $book = Book::where('slug', 'untouched')->firstOrFail();
        $this->assertCount(1, $book->versions);
        $this->assertSame(0, ReadInstance::where('book_id', $book->book_id)->count());
    }

    public function test_duplicate_slug_is_skipped_not_failed(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);
        Book::factory()->create(['title' => 'Dune', 'slug' => 'dune']);

        $file = $this->csvUpload([
            ['Dune', 'Frank', 'Herbert', 'Hardcover', '700', '', '', ''],
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $this->assertSame(['total' => 1, 'succeeded' => 0, 'skipped' => 1, 'failed' => 0], $response->json('summary'));
        $this->assertSame('skipped', $response->json('results.0.status'));
        $this->assertSame(1, Book::where('slug', 'dune')->count());
        $this->assertDatabaseMissing('authors', ['first_name' => 'Frank', 'last_name' => 'Herbert']);
    }

    public function test_unknown_format_fails_row_without_creating_book(): void
    {
        $this->actingAsUser();

        $file = $this->csvUpload([
            ['Lost Title', 'Some', 'One', 'NonexistentFormat', '200', '', '', ''],
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $this->assertSame(1, $response->json('summary.failed'));
        $this->assertStringContainsString("'NonexistentFormat' not found", $response->json('results.0.reason'));
        $this->assertDatabaseMissing('books', ['slug' => 'lost-title']);
    }

    public function test_missing_required_field_fails_row(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);

        $file = $this->csvUpload([
            ['', 'Ada', 'Lovelace', 'Hardcover', '200', '', '', ''],   // missing title
            ['Has Title', '', '', 'Hardcover', '200', '', '', ''],     // missing both author names
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $this->assertSame(2, $response->json('summary.failed'));
        $this->assertSame('failed', $response->json('results.0.status'));
        $this->assertSame('failed', $response->json('results.1.status'));
        $this->assertDatabaseMissing('books', ['slug' => 'has-title']);
    }

    public function test_per_row_failure_does_not_block_subsequent_rows(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);

        $file = $this->csvUpload([
            ['Good One', 'Ada', 'Lovelace', 'Hardcover', '100', '', '', ''],
            ['Bad One', 'Some', 'One', 'NotARealFormat', '200', '', '', ''],
            ['Another Good', 'Bob', 'Author', 'Hardcover', '150', '', '', ''],
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $this->assertSame(['total' => 3, 'succeeded' => 2, 'skipped' => 0, 'failed' => 1], $response->json('summary'));
        $this->assertDatabaseHas('books', ['slug' => 'good-one']);
        $this->assertDatabaseHas('books', ['slug' => 'another-good']);
        $this->assertDatabaseMissing('books', ['slug' => 'bad-one']);
    }

    public function test_blank_rows_are_ignored(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);

        $file = $this->csvUpload([
            ['', '', '', '', '', '', '', ''],
            ['Real Book', 'Ada', 'Lovelace', 'Hardcover', '100', '', '', ''],
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame(1, $response->json('summary.succeeded'));
        $this->assertSame(0, $response->json('summary.failed'));
    }

    public function test_genres_are_deduplicated_across_rows(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);

        $file = $this->csvUpload([
            ['Book A', 'Ada', 'Lovelace', 'Hardcover', '100', '', '', 'Fantasy'],
            ['Book B', 'Bob', 'Smith', 'Hardcover', '100', '', '', 'Fantasy, Mystery'],
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $this->assertSame(1, Genre::where('name', 'Fantasy')->count());
        $this->assertSame(1, Genre::where('name', 'Mystery')->count());
    }

    public function test_existing_author_is_reused_across_rows(): void
    {
        $this->actingAsUser();
        Format::factory()->create(['name' => 'Hardcover']);

        $file = $this->csvUpload([
            ['First Tolkien', 'J. R. R.', 'Tolkien', 'Hardcover', '300', '', '', ''],
            ['Second Tolkien', 'J. R. R.', 'Tolkien', 'Hardcover', '400', '', '', ''],
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $tolkiens = Author::where('last_name', 'Tolkien')->get();
        $this->assertCount(1, $tolkiens);
        $this->assertCount(2, $tolkiens->first()->books);
    }

    public function test_request_without_csv_file_fails_validation(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/bulk-upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('csv_file');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = $this->csvUpload([['Title', 'Ada', 'Lovelace', 'Hardcover', '100', '', '', '']]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertUnauthorized();
    }
}
