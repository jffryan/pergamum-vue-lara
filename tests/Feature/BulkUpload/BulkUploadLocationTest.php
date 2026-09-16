<?php

namespace Tests\Feature\BulkUpload;

use App\Models\Book;
use App\Models\Format;
use App\Models\Location;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The `location` CSV column: the shelf a copy sits on, as `CODE` or
 * `CODE|ordinal`. Optional, applied on version create only, and never
 * find-or-created — a typo must fail the row, not invent a shelf.
 */
class BulkUploadLocationTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'title,authors,format,page_count,location,version_nickname';

    private Location $shelf;

    private Location $otherShelf;

    protected function setUp(): void
    {
        parent::setUp();

        Format::create([
            'name' => 'Physical',
            'slug' => 'physical',
            'expects_page_count' => true,
            'expects_audio_runtime' => false,
        ]);

        $this->shelf = Location::factory()->create(['code' => 'O1S1', 'slug' => 'o1s1']);
        $this->otherShelf = Location::factory()->create(['code' => 'O1S2', 'slug' => 'o1s2']);
    }

    private function upload(string ...$rows)
    {
        return $this->uploadWith([], ...$rows);
    }

    private function uploadWith(array $options, string ...$rows)
    {
        $csv = implode("\n", [self::HEADER, ...$rows])."\n";

        return $this->postJson('/api/bulk-upload', $options + [
            'csv_file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
        ]);
    }

    private function row(string $location, string $nickname = '', string $title = 'Dune', string $authors = 'Frank|Herbert'): string
    {
        return implode(',', [$title, $authors, 'Physical', '604', $location, $nickname]);
    }

    public function test_a_new_version_lands_on_the_named_shelf(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('O1S1'))->assertOk();

        $this->assertDatabaseHas('versions', [
            'location_id' => $this->shelf->location_id,
            'shelf_ordinal' => null,
        ]);
    }

    public function test_the_ordinal_syntax_carries_shelf_position(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('O1S1|7'))->assertOk();

        $this->assertDatabaseHas('versions', [
            'location_id' => $this->shelf->location_id,
            'shelf_ordinal' => 7,
        ]);
    }

    public function test_matching_is_case_insensitive_via_the_slug(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('o1s1'))->assertOk();

        $this->assertDatabaseHas('versions', ['location_id' => $this->shelf->location_id]);
    }

    public function test_an_unknown_location_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row('O9S9'));

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'failed');
        $response->assertJsonPath('results.0.reason_code', 'location_not_found');
        $this->assertSame(0, Version::count());
    }

    public function test_two_locations_in_one_cell_are_malformed(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('O1S1;O1S2'))
            ->assertJsonPath('results.0.reason_code', 'location_entry_malformed');
    }

    public function test_a_non_numeric_ordinal_is_malformed(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('O1S1|left'))
            ->assertJsonPath('results.0.reason_code', 'location_entry_malformed');
    }

    /**
     * The copy-collapse guard: two rows sharing (title, format, nickname) but
     * naming different shelves describe two physical copies `resolveVersion`
     * would fold into one, silently losing a shelf assignment. The later row
     * fails; distinguishing nicknames are the fix.
     */
    public function test_same_copy_tuple_on_two_shelves_fails_the_later_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row('O1S1'), $this->row('O1S2'));

        $response->assertJsonPath('results.0.status', 'success');
        $response->assertJsonPath('results.1.status', 'failed');
        $response->assertJsonPath('results.1.reason_code', 'ambiguous_copy');
    }

    /**
     * Two same-title books by different authors are two books, so their
     * copies on two shelves are two copies — not an ambiguous one.
     */
    public function test_same_title_by_different_authors_on_two_shelves_is_two_copies(): void
    {
        $this->actingAsUser();

        $this->upload(
            $this->row('O1S1', '', 'Ariel', 'Sylvia|Plath'),
            $this->row('O1S2', '', 'Ariel', 'José Enrique|Rodó'),
        )->assertOk()->assertJsonPath('summary.failed', 0);

        $this->assertSame(2, Version::count());
        $this->assertDatabaseHas('versions', ['location_id' => $this->otherShelf->location_id]);
    }

    public function test_nicknames_disambiguate_two_copies_on_two_shelves(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('O1S1'), $this->row('O1S2', 'duplicate'))
            ->assertOk()
            ->assertJsonPath('summary.failed', 0);

        $this->assertSame(2, Version::count());
    }

    public function test_rereads_of_one_copy_may_repeat_its_location(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('O1S1'), $this->row('O1S1'))
            ->assertJsonPath('summary.failed', 0);

        $this->assertSame(1, Version::count());
    }

    // ---------- create_locations, the database-reset escape hatch ----------

    public function test_create_locations_rebuilds_the_shelf_chain_for_an_unknown_code(): void
    {
        $this->actingAsUser();

        $this->uploadWith(['create_locations' => true], $this->row('H2S3|4'))
            ->assertJsonPath('summary.failed', 0);

        $room = Location::where('slug', 'h')->first();
        $bookcase = Location::where('slug', 'h2')->first();
        $shelf = Location::where('slug', 'h2s3')->first();

        $this->assertSame('room', $room->kind);
        $this->assertSame($room->location_id, $bookcase->parent_id);
        $this->assertSame($bookcase->location_id, $shelf->parent_id);
        $this->assertDatabaseHas('versions', [
            'location_id' => $shelf->location_id,
            'shelf_ordinal' => 4,
        ]);
    }

    public function test_create_locations_lands_a_non_pattern_code_as_a_root_location(): void
    {
        $this->actingAsUser();

        $this->uploadWith(['create_locations' => true], $this->row('Attic Box'))
            ->assertJsonPath('summary.failed', 0);

        $this->assertDatabaseHas('locations', ['slug' => 'attic-box', 'parent_id' => null]);
    }

    public function test_a_dry_run_with_create_locations_writes_nothing(): void
    {
        $this->actingAsUser();
        $before = Location::count();

        $this->uploadWith(['create_locations' => true, 'dry_run' => true], $this->row('H2S3'))
            ->assertJsonPath('summary.failed', 0);

        $this->assertSame($before, Location::count());
        $this->assertSame(0, Version::count());
    }

    /**
     * The claim the flag exists to make: after `migrate:fresh` empties the
     * locations table, importing yesterday's export with `create_locations`
     * puts every copy back on a rebuilt shelf. See
     * /documentation/database-reset.md.
     */
    public function test_an_export_reimported_after_a_wipe_recovers_the_physical_layout(): void
    {
        $this->actingAsUser();
        $this->upload($this->row('O1S1|2'))->assertJsonPath('summary.failed', 0);

        $csv = $this->get('/api/export')->assertOk()->streamedContent();

        // The wipe: everything including the locations tree.
        Version::query()->update(['location_id' => null]);
        Book::query()->delete();
        Location::query()->delete();

        $this->postJson('/api/bulk-upload', [
            'csv_file' => UploadedFile::fake()->createWithContent('export.csv', $csv),
            'create_locations' => true,
        ])->assertOk()->assertJsonPath('summary.failed', 0);

        $shelf = Location::where('slug', 'o1s1')->first();
        $this->assertNotNull($shelf);
        $this->assertNotNull($shelf->parent_id);
        $this->assertDatabaseHas('versions', [
            'location_id' => $shelf->location_id,
            'shelf_ordinal' => 2,
        ]);
    }

    public function test_a_reimport_does_not_reshelve_an_existing_copy(): void
    {
        $this->actingAsUser();

        $this->upload($this->row('O1S1'))->assertOk();
        // The copy gets moved by hand after the file was written…
        Version::query()->update(['location_id' => $this->otherShelf->location_id]);

        // …so a re-import of the stale file must not move it back.
        $this->upload($this->row('O1S1'))->assertJsonPath('summary.failed', 0);

        $this->assertDatabaseHas('versions', ['location_id' => $this->otherShelf->location_id]);
        $this->assertSame(1, Version::count());
    }
}
