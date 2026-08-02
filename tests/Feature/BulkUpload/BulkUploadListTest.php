<?php

namespace Tests\Feature\BulkUpload;

use App\Models\Book;
use App\Models\BookList;
use App\Models\Format;
use App\Models\ListItem;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BulkUploadListTest extends TestCase
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

    private function csvFile(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADERS);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return new UploadedFile($path, 'books.csv', 'text/csv', null, true);
    }

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

    /**
     * A valid paperback row for the given title.
     */
    private function book(string $title, array $extra = []): array
    {
        return $this->row($extra + [
            'title' => $title,
            'authors' => 'Frank|Herbert',
            'format' => 'Paper',
            'page_count' => '300',
        ]);
    }

    public function test_happy_path_creates_the_list_and_files_every_version(): void
    {
        $user = $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->book('Dune'),
            $this->book('Messiah'),
        ]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Summer 2026 haul',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['list' => ['list_id', 'name', 'slug', 'items_added']]);
        $response->assertJsonPath('list.name', 'Summer 2026 haul');
        $response->assertJsonPath('list.slug', 'summer-2026-haul');
        $response->assertJsonPath('list.items_added', 2);

        $this->assertDatabaseHas('lists', [
            'name' => 'Summer 2026 haul',
            'slug' => 'summer-2026-haul',
            'user_id' => $user->user_id,
        ]);
        $this->assertSame(2, ListItem::count());
    }

    public function test_item_order_matches_csv_row_order(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->book('First'),
            $this->book('Second'),
            $this->book('Third'),
        ]);

        $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Ordered',
        ])->assertOk();

        $titles = ListItem::orderBy('ordinal')
            ->get()
            ->map(fn ($item) => Version::find($item->version_id)->book->title)
            ->all();

        $this->assertSame(['First', 'Second', 'Third'], $titles);
        $this->assertSame([0, 1, 2], ListItem::orderBy('ordinal')->pluck('ordinal')->all());
    }

    public function test_repeated_rows_for_one_version_produce_a_single_list_item(): void
    {
        $this->actingAsUser();
        $this->paper();

        // Three re-reads of the same paperback resolve to one version.
        $file = $this->csvFile([
            $this->book('Dune', ['date_read' => '2024-01-01']),
            $this->book('Dune', ['date_read' => '2025-01-01']),
            $this->book('Dune', ['date_read' => '2026-01-01']),
        ]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Rereads',
        ]);

        $response->assertOk();
        $response->assertJsonPath('summary.succeeded', 3);
        $response->assertJsonPath('list.items_added', 1);
        $this->assertSame(1, ListItem::count());
        $this->assertSame(3, ReadInstance::count());
    }

    public function test_two_formats_of_one_book_produce_two_items(): void
    {
        $this->actingAsUser();
        $this->paper();
        $this->audiobook();

        $file = $this->csvFile([
            $this->book('Dune'),
            $this->row([
                'title' => 'Dune',
                'authors' => 'Frank|Herbert',
                'format' => 'Audiobook',
                'audio_runtime' => '1260',
            ]),
        ]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Both formats',
        ]);

        $response->assertOk();
        $response->assertJsonPath('list.items_added', 2);
        $this->assertSame(1, Book::count());
        $this->assertSame(2, ListItem::count());
    }

    public function test_failed_rows_are_absent_from_the_list(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->book('Good One'),
            $this->row(['title' => 'Bad One', 'authors' => 'A|B', 'format' => 'Papyrus']),
            $this->book('Good Two'),
        ]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Partial',
        ]);

        $response->assertOk();
        $response->assertJsonPath('summary.failed', 1);
        $response->assertJsonPath('list.items_added', 2);
        $this->assertSame(2, ListItem::count());
    }

    public function test_every_row_failing_creates_no_list_and_leaves_the_name_free(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->row(['title' => 'Bad', 'authors' => 'A|B', 'format' => 'Papyrus']),
        ]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Never Made',
        ]);

        $response->assertOk();
        $response->assertJsonPath('list.list_id', null);
        $response->assertJsonPath('list.items_added', 0);
        $this->assertSame(0, BookList::count());

        // The name is still free, so a corrected retry does not collide.
        $this->paper();
        $retry = $this->csvFile([$this->book('Dune')]);
        $this->postJson('/api/bulk-upload', [
            'csv_file' => $retry,
            'list_name' => 'Never Made',
        ])->assertOk()->assertJsonPath('list.items_added', 1);
    }

    public function test_name_collision_returns_422_and_writes_nothing(): void
    {
        $user = $this->actingAsUser();
        $this->paper();

        BookList::create([
            'name' => 'Taken',
            'slug' => 'taken',
            'user_id' => $user->user_id,
        ]);

        $file = $this->csvFile([$this->book('Dune')]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Taken',
        ]);

        $response->assertStatus(422);
        $this->assertSame('list_name_taken', $response->json('reason_code'));
        $this->assertSame("a list named 'Taken' already exists", $response->json('reason'));
        $this->assertSame(0, Book::count());
        $this->assertSame(0, Version::count());
        $this->assertSame(0, ReadInstance::count());
        $this->assertSame(1, ListItem::count() + BookList::count());
    }

    public function test_collision_is_slug_based_not_case_sensitive(): void
    {
        $user = $this->actingAsUser();
        $this->paper();

        BookList::create(['name' => 'My List', 'slug' => 'my-list', 'user_id' => $user->user_id]);

        $file = $this->csvFile([$this->book('Dune')]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'my list',
        ]);

        $response->assertStatus(422);
        $this->assertSame('list_name_taken', $response->json('reason_code'));
    }

    public function test_another_users_list_of_the_same_name_does_not_collide(): void
    {
        $other = User::factory()->create();
        BookList::create(['name' => 'Shared Name', 'slug' => 'shared-name', 'user_id' => $other->user_id]);

        $user = $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([$this->book('Dune')]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Shared Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('list.items_added', 1);
        $this->assertSame(2, BookList::count());
        $this->assertDatabaseHas('lists', ['slug' => 'shared-name', 'user_id' => $user->user_id]);
    }

    public function test_dry_run_creates_no_list_or_items_but_reports_an_exact_count(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([
            $this->book('Dune'),
            $this->book('Messiah'),
            $this->book('Dune'),
        ]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Preview',
            'dry_run' => '1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('dry_run', true);
        $response->assertJsonPath('list.list_id', null);
        // Two distinct versions across three rows.
        $response->assertJsonPath('list.items_added', 2);
        $this->assertSame(0, BookList::count());
        $this->assertSame(0, ListItem::count());
        $this->assertSame(0, Book::count());
    }

    public function test_dry_run_still_rejects_a_taken_name(): void
    {
        $user = $this->actingAsUser();
        $this->paper();

        BookList::create(['name' => 'Taken', 'slug' => 'taken', 'user_id' => $user->user_id]);

        $file = $this->csvFile([$this->book('Dune')]);

        $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Taken',
            'dry_run' => '1',
        ])->assertStatus(422)->assertJsonPath('reason_code', 'list_name_taken');
    }

    public function test_omitting_list_name_reports_a_null_list(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([$this->book('Dune')]);

        $response = $this->postJson('/api/bulk-upload', ['csv_file' => $file]);

        $response->assertOk();
        $this->assertNull($response->json('list'));
        $this->assertSame(0, BookList::count());
        $this->assertSame(0, ListItem::count());
    }

    public function test_name_collision_is_reported_ahead_of_an_invalid_header(): void
    {
        $user = $this->actingAsUser();
        BookList::create(['name' => 'Taken', 'slug' => 'taken', 'user_id' => $user->user_id]);

        $path = tempnam(sys_get_temp_dir(), 'csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['title', 'nonsense']);
        fclose($handle);
        $file = new UploadedFile($path, 'books.csv', 'text/csv', null, true);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => 'Taken',
        ]);

        $response->assertStatus(422);
        $this->assertSame('list_name_taken', $response->json('reason_code'));
    }

    public function test_blank_list_name_is_a_standard_validation_error(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([$this->book('Dune')]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('list_name');
        $this->assertSame(0, Book::count());
    }

    public function test_over_length_list_name_is_a_standard_validation_error(): void
    {
        $this->actingAsUser();
        $this->paper();

        $file = $this->csvFile([$this->book('Dune')]);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $file,
            'list_name' => str_repeat('a', 256),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('list_name');
    }
}
