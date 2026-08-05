<?php

namespace Tests\Feature\BulkUpload;

use App\Models\BookList;
use App\Models\Format;
use App\Models\ListItem;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The three columns the export added to the CSV contract: `is_discarded`,
 * `discarded_at` and `lists`. All optional, so every file that was valid before
 * still is.
 */
class BulkUploadNewColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'title,authors,format,page_count,audio_runtime,version_nickname,genres,date_read,rating,is_discarded,discarded_at,lists';

    protected function setUp(): void
    {
        parent::setUp();

        Format::create([
            'name' => 'Physical',
            'slug' => 'physical',
            'expects_page_count' => true,
            'expects_audio_runtime' => false,
        ]);
    }

    private function upload(string ...$rows)
    {
        $csv = implode("\n", [self::HEADER, ...$rows])."\n";

        return $this->postJson('/api/bulk-upload', [
            'csv_file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
        ]);
    }

    /** @param  array<int, string>  $overrides  keyed by column name */
    private function row(array $overrides = []): string
    {
        $cells = array_replace([
            'title' => 'Dune', 'authors' => 'Frank|Herbert', 'format' => 'Physical',
            'page_count' => '604', 'audio_runtime' => '', 'version_nickname' => '',
            'genres' => '', 'date_read' => '', 'rating' => '',
            'is_discarded' => '', 'discarded_at' => '', 'lists' => '',
        ], $overrides);

        return implode(',', array_map(
            fn ($cell) => str_contains($cell, ',') ? '"'.$cell.'"' : $cell,
            $cells
        ));
    }

    // ---------- is_discarded / discarded_at ----------

    public function test_discarded_flag_and_date_are_stored_on_a_new_version(): void
    {
        $this->actingAsUser();

        $this->upload($this->row(['is_discarded' => '1', 'discarded_at' => '2021-06-15']))->assertOk();

        $version = Version::first();
        $this->assertTrue((bool) $version->is_discarded);
        $this->assertSame('2021-06-15', $version->discarded_at->format('Y-m-d'));
    }

    public function test_discarded_without_a_date_is_the_date_unknown_case(): void
    {
        $this->actingAsUser();

        $this->upload($this->row(['is_discarded' => 'true']))->assertOk();

        $version = Version::first();
        $this->assertTrue((bool) $version->is_discarded);
        $this->assertNull($version->discarded_at);
    }

    public function test_omitting_the_flag_leaves_the_copy_on_the_shelf(): void
    {
        $this->actingAsUser();

        $this->upload($this->row())->assertOk();

        $this->assertFalse((bool) Version::first()->is_discarded);
    }

    public function test_a_non_boolean_flag_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row(['is_discarded' => 'maybe']));

        $response->assertOk();
        $this->assertSame('is_discarded_invalid', $response->json('results.0.reason_code'));
        $this->assertSame(0, Version::count());
    }

    public function test_a_discard_date_without_the_flag_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row(['discarded_at' => '2021-06-15']));

        $response->assertOk();
        $this->assertSame('discarded_at_without_flag', $response->json('results.0.reason_code'));
    }

    public function test_an_unparseable_discard_date_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row(['is_discarded' => '1', 'discarded_at' => 'last summer']));

        $response->assertOk();
        $this->assertSame('date_parse_failed', $response->json('results.0.reason_code'));
    }

    public function test_a_matched_version_keeps_the_discard_state_it_already_had(): void
    {
        $this->actingAsUser();

        $this->upload($this->row(['is_discarded' => '1', 'discarded_at' => '2021-06-15']))->assertOk();
        // Re-importing an older file that predates the discard must not
        // resurrect the copy — same rule as page_count on a matched version.
        $this->upload($this->row(['is_discarded' => '0']))->assertOk();

        $this->assertSame(1, Version::count());
        $this->assertTrue((bool) Version::first()->is_discarded);
    }

    // ---------- lists ----------

    public function test_a_named_list_is_created_and_the_version_appended(): void
    {
        $user = $this->actingAsUser();

        $this->upload($this->row(['lists' => 'Want to Read']))->assertOk();

        $list = BookList::where('user_id', $user->user_id)->first();
        $this->assertSame('Want to Read', $list->name);
        $this->assertSame('want-to-read', $list->slug);
        $this->assertSame(1, $list->items()->count());
    }

    public function test_an_explicit_ordinal_is_honoured(): void
    {
        $this->actingAsUser();

        $this->upload($this->row(['lists' => 'Want to Read|7']))->assertOk();

        $this->assertSame(7, ListItem::first()->ordinal);
    }

    public function test_one_version_can_sit_on_two_lists_at_different_positions(): void
    {
        $user = $this->actingAsUser();

        $this->upload($this->row(['lists' => 'Want to Read|0;Favourites|3']))->assertOk();

        $this->assertSame(2, BookList::where('user_id', $user->user_id)->count());
        $ordinals = ListItem::orderBy('list_id')->pluck('ordinal')->all();
        $this->assertSame([0, 3], $ordinals);
    }

    public function test_repeating_a_list_across_re_read_rows_appends_once(): void
    {
        $this->actingAsUser();

        // Two reads of the same copy, each row naming the same list — the
        // (list_id, version_id) unique index would otherwise fail the second.
        $this->upload(
            $this->row(['date_read' => '2023-01-01', 'lists' => 'Favourites']),
            $this->row(['date_read' => '2024-01-01', 'lists' => 'Favourites']),
        )->assertOk();

        $this->assertSame(1, ListItem::count());
    }

    public function test_an_existing_list_of_the_same_name_is_reused_not_rejected(): void
    {
        $user = $this->actingAsUser();
        BookList::create(['name' => 'Favourites', 'slug' => 'favourites', 'user_id' => $user->user_id]);

        $this->upload($this->row(['lists' => 'Favourites']))->assertOk();

        $this->assertSame(1, BookList::where('user_id', $user->user_id)->count());
        $this->assertSame(1, ListItem::count());
    }

    public function test_lists_are_created_for_the_importing_user_only(): void
    {
        $other = User::factory()->create();
        BookList::create(['name' => 'Favourites', 'slug' => 'favourites', 'user_id' => $other->user_id]);

        $user = $this->actingAsUser();
        $this->upload($this->row(['lists' => 'Favourites']))->assertOk();

        // The other account's identically-named list is untouched; a new one is
        // created for the importer.
        $this->assertSame(2, BookList::count());
        $this->assertSame(1, BookList::where('user_id', $user->user_id)->count());
    }

    public function test_a_malformed_list_entry_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row(['lists' => 'Want to Read|not-a-number']));

        $response->assertOk();
        $this->assertSame('list_entry_malformed', $response->json('results.0.reason_code'));
        $this->assertSame(0, BookList::count());
    }

    public function test_a_dry_run_files_nothing_into_a_list(): void
    {
        $this->actingAsUser();

        $csv = implode("\n", [self::HEADER, $this->row(['lists' => 'Want to Read'])])."\n";

        $this->postJson('/api/bulk-upload', [
            'csv_file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            'dry_run' => 1,
        ])->assertOk();

        $this->assertSame(0, BookList::count());
        $this->assertSame(0, ListItem::count());
    }

    // ---------- backwards compatibility ----------

    public function test_the_old_nine_column_header_is_still_accepted(): void
    {
        $this->actingAsUser();

        $csv = "title,authors,format,page_count,audio_runtime,version_nickname,genres,date_read,rating\n"
            ."Dune,Frank|Herbert,Physical,604,,,,2024-01-01,4\n";

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.failed'));
        $this->assertSame(1, Version::count());
    }

    public function test_the_three_new_columns_may_be_omitted_individually(): void
    {
        $this->actingAsUser();

        $csv = "title,authors,format,page_count,lists\n"
            ."Dune,Frank|Herbert,Physical,604,Favourites\n";

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.failed'));
        $this->assertSame(1, ListItem::count());
    }
}
