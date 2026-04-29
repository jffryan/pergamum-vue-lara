<?php

namespace Tests\Feature\BulkUpload;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'title',
        'authors',
        'format',
        'page_count',
        'audio_runtime',
        'version_nickname',
        'genres',
        'date_read',
        'rating',
    ];

    private function csvFile(array $rows, ?array $headers = null, string $filename = 'books.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers ?? self::HEADERS);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    /**
     * Build a row keyed by header name; any unspecified columns default to ''.
     */
    private function row(array $values): array
    {
        $row = [];
        foreach (self::HEADERS as $header) {
            $row[] = (string) ($values[$header] ?? '');
        }

        return $row;
    }

    private function paper(): Format
    {
        return Format::factory()->create(['name' => 'Paper', 'slug' => 'paper']);
    }

    private function audiobook(): Format
    {
        return Format::factory()->create(['name' => 'Audiobook', 'slug' => 'audiobook']);
    }

    // ---------- Header / file shape ----------

    public function test_happy_path_creates_book_author_version_and_read_instance(): void
    {
        $user = $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row([
                'title' => 'The Hobbit',
                'authors' => 'J. R. R.|Tolkien',
                'format' => 'Paper',
                'page_count' => '310',
                'date_read' => '2024-06-15',
                'rating' => '5',
                'genres' => 'Fantasy; Adventure',
            ]),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertOk();
        $this->assertSame(
            ['total' => 1, 'succeeded' => 1, 'skipped' => 0, 'failed' => 0],
            $response->json('summary'),
        );

        $book = Book::where('slug', 'the-hobbit')->firstOrFail();
        $this->assertCount(1, $book->authors);
        $this->assertCount(2, $book->genres);
        $this->assertCount(1, $book->versions);
        $this->assertSame(310, $book->versions->first()->page_count);
        $this->assertDatabaseHas('read_instances', [
            'book_id' => $book->book_id,
            'user_id' => $user->user_id,
            'date_read' => '2024-06-15',
            'rating' => 10, // doubled
        ]);
    }

    public function test_header_missing_required_column_returns_422_with_no_writes(): void
    {
        $this->actingAsUser();
        $this->paper();

        $headers = self::HEADERS;
        unset($headers[1]); // drop "authors"
        $headers = array_values($headers);

        $row = ['Whatever', 'Paper', '300', '', '', '', '', ''];
        $file = $this->csvFile([$row], $headers);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertStatus(422);
        $this->assertSame('header_invalid', $response->json('reason_code'));
        $this->assertSame(0, Book::count());
    }

    public function test_header_with_unknown_column_returns_422(): void
    {
        $this->actingAsUser();
        $headers = array_merge(self::HEADERS, ['mystery_column']);
        $file = $this->csvFile([], $headers);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertStatus(422);
        $this->assertSame('header_invalid', $response->json('reason_code'));
    }

    public function test_header_is_case_insensitive_and_trim_tolerant(): void
    {
        $this->actingAsUser();
        $this->paper();

        $headers = ['Title', ' AUTHORS ', 'Format', 'page_count', 'AUDIO_RUNTIME', 'version_nickname', 'genres', 'date_read', 'rating'];
        $file = $this->csvFile([
            ['Foo', 'Ada|Lovelace', 'Paper', '120', '', '', '', '', ''],
        ], $headers);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.succeeded'));
        $this->assertDatabaseHas('books', ['slug' => 'foo']);
    }

    public function test_empty_file_with_only_header_returns_zero_summary(): void
    {
        $this->actingAsUser();
        $file = $this->csvFile([]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertOk();
        $this->assertSame(
            ['total' => 0, 'succeeded' => 0, 'skipped' => 0, 'failed' => 0],
            $response->json('summary'),
        );
    }

    public function test_blank_rows_in_middle_are_skipped_silently(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Real', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '10']),
            ['', '', '', '', '', '', '', '', ''],
            $this->row(['title' => 'Real Two', 'authors' => 'C|D', 'format' => 'Paper', 'page_count' => '10']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertOk();
        // total counts every data row (existing behavior), including the blank one we silently skip.
        $this->assertSame(3, $response->json('summary.total'));
        $this->assertSame(2, $response->json('summary.succeeded'));
        $this->assertSame(0, $response->json('summary.failed'));
    }

    // ---------- Data fidelity ----------

    public function test_multi_read_creates_multiple_read_instances_against_one_version(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Reread', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-01-01', 'rating' => '4']),
            $this->row(['title' => 'Reread', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2025-01-01', 'rating' => '5']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $this->assertSame(1, Book::count());
        $this->assertSame(1, Version::count());
        $this->assertSame(2, ReadInstance::count());
    }

    public function test_multi_version_keeps_read_instances_attached_to_correct_versions(): void
    {
        $this->actingAsUser();
        $this->paper();
        $this->audiobook();

        $file = $this->csvFile([
            $this->row(['title' => 'Same Book', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '300', 'date_read' => '2024-02-02']),
            $this->row(['title' => 'Same Book', 'authors' => 'A|B', 'format' => 'Audiobook', 'audio_runtime' => '720', 'date_read' => '2024-03-03']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $book = Book::where('slug', 'same-book')->firstOrFail();
        $this->assertCount(2, $book->versions);
        $paper = $book->versions->firstWhere('page_count', 300);
        $audio = $book->versions->firstWhere('audio_runtime', 720);
        $this->assertNotNull($paper);
        $this->assertNotNull($audio);
        $this->assertSame(1, $paper->readInstances()->count());
        $this->assertSame(1, $audio->readInstances()->count());
    }

    public function test_multi_author_row_attaches_each_author(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Co Authored', 'authors' => 'Jane|Smith;John|Doe', 'format' => 'Paper', 'page_count' => '200']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $book = Book::where('slug', 'co-authored')->firstOrFail();
        $this->assertCount(2, $book->authors);
        $this->assertSame(['jane-smith', 'john-doe'], $book->authors->pluck('slug')->sort()->values()->all());
    }

    public function test_audiobook_row_persists_runtime_with_blank_page_count(): void
    {
        $this->actingAsUser();
        $this->audiobook();

        $file = $this->csvFile([
            $this->row(['title' => 'A Memory', 'authors' => 'A|B', 'format' => 'Audiobook', 'audio_runtime' => '540']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $version = Version::firstOrFail();
        $this->assertSame(540, $version->audio_runtime);
    }

    public function test_genre_case_dedupe_across_rows(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'A', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'genres' => 'Fantasy']),
            $this->row(['title' => 'B', 'authors' => 'C|D', 'format' => 'Paper', 'page_count' => '100', 'genres' => 'fantasy']),
            $this->row(['title' => 'C', 'authors' => 'E|F', 'format' => 'Paper', 'page_count' => '100', 'genres' => ' Fantasy ']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame(1, Genre::count());
    }

    public function test_long_title_gets_clean_slug_without_truncation_suffix(): void
    {
        $this->actingAsUser();
        $this->paper();

        $longTitle = str_repeat('Word ', 20).'End';
        $file = $this->csvFile([
            $this->row(['title' => $longTitle, 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $book = Book::firstOrFail();
        $this->assertStringNotContainsString('...', $book->slug);
        $this->assertStringNotContainsString('.', $book->slug);
    }

    // ---------- Per-row failures ----------

    public function test_missing_title_reports_missing_required_field(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => '', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('failed', $response->json('results.0.status'));
        $this->assertSame('missing_required_field', $response->json('results.0.reason_code'));
    }

    public function test_missing_format_reports_missing_required_field(): void
    {
        $this->actingAsUser();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => '', 'page_count' => '100']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('missing_required_field', $response->json('results.0.reason_code'));
    }

    public function test_unknown_format_reports_format_not_found(): void
    {
        $this->actingAsUser();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Vinyl', 'page_count' => '100']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('format_not_found', $response->json('results.0.reason_code'));
        $this->assertSame(0, Book::count());
    }

    public function test_audiobook_blank_runtime_reports_audio_runtime_required(): void
    {
        $this->actingAsUser();
        $this->audiobook();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Audiobook']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('audio_runtime_required', $response->json('results.0.reason_code'));
    }

    public function test_non_audio_blank_page_count_reports_page_count_required(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Paper']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('page_count_required', $response->json('results.0.reason_code'));
    }

    public function test_malformed_date_reports_date_parse_failed(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => 'tomorrow']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('date_parse_failed', $response->json('results.0.reason_code'));
    }

    public function test_non_numeric_rating_reports_rating_not_numeric(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-01-01', 'rating' => 'abc']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('rating_not_numeric', $response->json('results.0.reason_code'));
    }

    public function test_rating_out_of_range_reports_rating_out_of_range(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-01-01', 'rating' => '7']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('rating_out_of_range', $response->json('results.0.reason_code'));
    }

    public function test_rating_non_half_step_reports_rating_out_of_range(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-01-01', 'rating' => '3.7']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('rating_out_of_range', $response->json('results.0.reason_code'));
    }

    public function test_author_entry_with_both_halves_blank_reports_malformed(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => '|', 'format' => 'Paper', 'page_count' => '100']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame('author_entry_malformed', $response->json('results.0.reason_code'));
    }

    public function test_failure_does_not_block_subsequent_rows(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Good', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100']),
            $this->row(['title' => 'Bad', 'authors' => 'A|B', 'format' => 'Vinyl', 'page_count' => '100']),
            $this->row(['title' => 'Good Two', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();
        $this->assertSame(2, $response->json('summary.succeeded'));
        $this->assertSame(1, $response->json('summary.failed'));
    }

    // ---------- Idempotency ----------

    public function test_reupload_reuses_book_author_version_creates_new_read_instance(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file1 = $this->csvFile([
            $this->row(['title' => 'Same', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-01-01']),
        ]);
        $file2 = $this->csvFile([
            $this->row(['title' => 'Same', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-02-01']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file1])->assertOk();
        $this->postJson('/api/bulk-upload', ['csv_file' => $file2])->assertOk();

        $this->assertSame(1, Book::count());
        $this->assertSame(1, Author::count());
        $this->assertSame(1, Version::count());
        $this->assertSame(2, ReadInstance::count());
    }

    // ---------- User scoping ----------

    public function test_each_user_gets_their_own_read_instance_against_shared_book(): void
    {
        $userA = $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Shared', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-01-01']),
        ]);
        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertOk();

        $userB = User::factory()->create();
        $this->actingAs($userB, 'sanctum');

        $file2 = $this->csvFile([
            $this->row(['title' => 'Shared', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-02-02']),
        ]);
        $this->postJson('/api/bulk-upload', ['csv_file' => $file2])->assertOk();

        $this->assertSame(1, Book::count());
        $this->assertSame(1, ReadInstance::where('user_id', $userA->user_id)->count());
        $this->assertSame(1, ReadInstance::where('user_id', $userB->user_id)->count());
    }

    // ---------- Dry run ----------

    public function test_dry_run_reports_results_without_persisting(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Phantom', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100', 'date_read' => '2024-01-01']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file, 'dry_run' => '1']);
        $response->assertOk();

        $this->assertSame(1, $response->json('summary.succeeded'));
        $this->assertTrue($response->json('dry_run'));
        $this->assertSame(0, Book::count());
        $this->assertSame(0, Author::count());
        $this->assertSame(0, ReadInstance::count());
    }

    public function test_dry_run_still_reports_per_row_failure(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Good', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100']),
            $this->row(['title' => 'Bad', 'authors' => 'A|B', 'format' => 'Vinyl', 'page_count' => '100']),
        ]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file, 'dry_run' => '1'])->assertOk();
        $this->assertSame(1, $response->json('summary.succeeded'));
        $this->assertSame(1, $response->json('summary.failed'));
        $this->assertSame(0, Book::count());
    }

    public function test_header_invalid_returns_422_regardless_of_dry_run(): void
    {
        $this->actingAsUser();

        $headers = self::HEADERS;
        unset($headers[0]); // drop title
        $file = $this->csvFile([], array_values($headers));

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file, 'dry_run' => '1']);
        $response->assertStatus(422);
        $this->assertSame('header_invalid', $response->json('reason_code'));
    }

    // ---------- Existing safeguards ----------

    public function test_request_without_csv_file_fails_validation(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/bulk-upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('csv_file');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = $this->csvFile([
            $this->row(['title' => 'X', 'authors' => 'A|B', 'format' => 'Paper', 'page_count' => '100']),
        ]);

        $this->postJson('/api/bulk-upload', ['csv_file' => $file])->assertUnauthorized();
    }
}
