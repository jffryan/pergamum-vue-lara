<?php

namespace Tests\Feature\Export;

use App\Models\Book;
use App\Models\Format;
use App\Models\Location;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `location` column on the way out. Without it a database reset would
 * silently lose the physical layout of the library — the exact failure class
 * `CsvContract` exists to prevent.
 */
class ExportLocationTest extends TestCase
{
    use RefreshDatabase;

    private function export(): array
    {
        $csv = $this->get('/api/export')->assertOk()->streamedContent();

        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $header = array_shift($rows);

        return array_map(fn ($row) => array_combine($header, $row), $rows);
    }

    private function shelvedVersion(?int $ordinal): Version
    {
        $format = Format::create([
            'name' => 'Physical',
            'slug' => 'physical',
            'expects_page_count' => true,
            'expects_audio_runtime' => false,
        ]);
        $shelf = Location::factory()->create(['code' => 'O1S5', 'slug' => 'o1s5']);

        return Version::factory()
            ->for(Book::factory()->create(), 'book')
            ->create([
                'format_id' => $format->format_id,
                'location_id' => $shelf->location_id,
                'shelf_ordinal' => $ordinal,
            ]);
    }

    public function test_a_shelved_copy_exports_its_code_and_position(): void
    {
        $this->actingAsUser();
        $this->shelvedVersion(3);

        $rows = $this->export();

        $this->assertSame('O1S5|3', $rows[0]['location']);
    }

    public function test_a_copy_without_a_position_exports_the_bare_code(): void
    {
        $this->actingAsUser();
        $this->shelvedVersion(null);

        $rows = $this->export();

        $this->assertSame('O1S5', $rows[0]['location']);
    }

    public function test_an_unshelved_copy_exports_an_empty_cell(): void
    {
        $this->actingAsUser();
        Version::factory()->create();

        $rows = $this->export();

        $this->assertSame('', $rows[0]['location']);
    }
}
