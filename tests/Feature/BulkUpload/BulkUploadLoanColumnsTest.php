<?php

namespace Tests\Feature\BulkUpload;

use App\Models\Format;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * `is_on_loan`, `loaned_to` and `loaned_at` — the loan half of a copy's
 * state, carried so a database reset doesn't forget who has what. Optional
 * columns, create-only like the discard pair.
 */
class BulkUploadLoanColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'title,authors,format,page_count,is_discarded,is_on_loan,loaned_to,loaned_at';

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

    /** @param  array<string, string>  $overrides  keyed by column name */
    private function row(array $overrides = []): string
    {
        $cells = array_replace([
            'title' => 'Dune', 'authors' => 'Frank|Herbert', 'format' => 'Physical', 'page_count' => '604',
            'is_discarded' => '', 'is_on_loan' => '', 'loaned_to' => '', 'loaned_at' => '',
        ], $overrides);

        return implode(',', array_map(
            fn ($cell) => str_contains($cell, ',') ? '"'.$cell.'"' : $cell,
            $cells
        ));
    }

    public function test_loan_flag_and_details_are_stored_on_a_new_version(): void
    {
        $this->actingAsUser();

        $this->upload($this->row(['is_on_loan' => '1', 'loaned_to' => 'Sam, next door', 'loaned_at' => '2025-02-03']))
            ->assertOk();

        $version = Version::first();
        $this->assertTrue($version->is_on_loan);
        $this->assertSame('Sam, next door', $version->loaned_to);
        $this->assertSame('2025-02-03', $version->loaned_at->format('Y-m-d'));
    }

    public function test_a_loan_with_no_details_is_accepted(): void
    {
        $this->actingAsUser();

        $this->upload($this->row(['is_on_loan' => 'yes']))->assertOk();

        $version = Version::first();
        $this->assertTrue($version->is_on_loan);
        $this->assertNull($version->loaned_to);
        $this->assertNull($version->loaned_at);
    }

    public function test_a_non_boolean_flag_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row(['is_on_loan' => 'kinda']));

        $this->assertSame('is_on_loan_invalid', $response->json('results.0.reason_code'));
        $this->assertSame(0, Version::count());
    }

    public function test_loan_details_without_the_flag_fail_the_row(): void
    {
        $this->actingAsUser();

        $this->assertSame(
            'loan_details_without_flag',
            $this->upload($this->row(['loaned_to' => 'Sam']))->json('results.0.reason_code'),
        );
        $this->assertSame(
            'loan_details_without_flag',
            $this->upload($this->row(['loaned_at' => '2025-02-03']))->json('results.0.reason_code'),
        );
    }

    public function test_a_discarded_copy_on_loan_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row(['is_on_loan' => '1', 'is_discarded' => '1']));

        $this->assertSame('loan_on_discarded_copy', $response->json('results.0.reason_code'));
    }

    public function test_an_unparseable_loan_date_fails_the_row(): void
    {
        $this->actingAsUser();

        $response = $this->upload($this->row(['is_on_loan' => '1', 'loaned_at' => 'last spring']));

        $this->assertSame('date_parse_failed', $response->json('results.0.reason_code'));
    }

    public function test_a_matched_version_keeps_the_loan_state_it_already_had(): void
    {
        $this->actingAsUser();

        $this->upload($this->row())->assertOk();
        // An older file that still says "lent" must not re-lend a copy that
        // came back — create-only, like the discard pair.
        $this->upload($this->row(['is_on_loan' => '1', 'loaned_to' => 'Sam']))->assertOk();

        $this->assertSame(1, Version::count());
        $this->assertFalse(Version::first()->is_on_loan);
    }
}
