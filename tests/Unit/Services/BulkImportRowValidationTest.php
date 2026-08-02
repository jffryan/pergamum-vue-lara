<?php

namespace Tests\Unit\Services;

use App\Models\Format;
use App\Services\BulkImportService;
use App\Services\Exceptions\BulkImportRowException;
use PHPUnit\Framework\TestCase;

/**
 * BulkImportService::validateRow is pure — no file handle, no database — so these run
 * against a plain array of cells and an in-memory Format.
 */
class BulkImportRowValidationTest extends TestCase
{
    private BulkImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BulkImportService;
    }

    private function format(string $name): Format
    {
        $format = new Format;
        $format->name = $name;

        return $format;
    }

    /**
     * A row that passes every gate; individual tests override single cells.
     */
    private function cells(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'A Title',
            'authors' => 'Ursula|Le Guin',
            'format' => 'Paper',
            'page_count' => '300',
            'audio_runtime' => '',
            'version_nickname' => '',
            'genres' => '',
            'date_read' => '',
            'rating' => '',
        ];
    }

    private function assertRejects(string $expectedReasonCode, array $cells, ?Format $format): void
    {
        try {
            $this->service->validateRow($cells, $format);
        } catch (BulkImportRowException $e) {
            $this->assertSame($expectedReasonCode, $e->reasonCode);

            return;
        }

        $this->fail("Expected reason code {$expectedReasonCode}, but the row validated.");
    }

    public function test_a_complete_row_produces_a_populated_import_row(): void
    {
        $format = $this->format('Paper');

        $row = $this->service->validateRow($this->cells([
            'version_nickname' => 'First edition',
            'genres' => 'Fantasy; Sci-Fi',
            'date_read' => '2024-03-01',
            'rating' => '4.5',
        ]), $format);

        $this->assertSame('A Title', $row->title);
        $this->assertSame($format, $row->format);
        $this->assertSame(300, $row->pageCount);
        $this->assertNull($row->audioRuntime);
        $this->assertSame('First edition', $row->nickname);
        $this->assertSame(['Fantasy', 'Sci-Fi'], $row->genres);
        $this->assertSame(4.5, $row->rating);
        $this->assertSame('2024-03-01', $row->dateRead->format('Y-m-d'));
        $this->assertSame([['first' => 'Ursula', 'last' => 'Le Guin', 'slug' => 'ursula-le-guin']], $row->authors);
    }

    public function test_a_blank_nickname_becomes_null(): void
    {
        $row = $this->service->validateRow($this->cells(), $this->format('Paper'));

        $this->assertNull($row->nickname);
        $this->assertNull($row->dateRead);
        $this->assertNull($row->rating);
        $this->assertSame([], $row->genres);
    }

    public function test_missing_title_is_rejected(): void
    {
        $this->assertRejects('missing_required_field', $this->cells(['title' => '']), $this->format('Paper'));
    }

    public function test_missing_authors_is_rejected(): void
    {
        $this->assertRejects('missing_required_field', $this->cells(['authors' => '']), $this->format('Paper'));
    }

    public function test_missing_format_is_rejected_before_the_lookup_result_matters(): void
    {
        $this->assertRejects('missing_required_field', $this->cells(['format' => '']), null);
    }

    public function test_an_unresolved_format_is_rejected(): void
    {
        $this->assertRejects('format_not_found', $this->cells(['format' => 'Papyrus']), null);
    }

    public function test_audiobook_row_requires_a_runtime(): void
    {
        $cells = $this->cells(['format' => 'Audiobook', 'page_count' => '', 'audio_runtime' => '']);

        $this->assertRejects('audio_runtime_required', $cells, $this->format('Audiobook'));
    }

    public function test_audiobook_row_defaults_page_count_to_zero(): void
    {
        $cells = $this->cells(['format' => 'Audiobook', 'page_count' => '', 'audio_runtime' => '480']);

        $row = $this->service->validateRow($cells, $this->format('Audiobook'));

        $this->assertSame(0, $row->pageCount);
        $this->assertSame(480, $row->audioRuntime);
    }

    public function test_audiobook_matching_is_case_insensitive(): void
    {
        $cells = $this->cells(['format' => 'audiobook', 'page_count' => '', 'audio_runtime' => '']);

        $this->assertRejects('audio_runtime_required', $cells, $this->format('audiobook'));
    }

    public function test_non_audio_row_requires_a_page_count(): void
    {
        $this->assertRejects('page_count_required', $this->cells(['page_count' => '']), $this->format('Paper'));
    }

    public function test_malformed_author_entry_is_rejected(): void
    {
        $this->assertRejects('author_entry_malformed', $this->cells(['authors' => 'Ursula Le Guin']), $this->format('Paper'));
    }

    public function test_non_numeric_rating_keeps_its_own_reason_code(): void
    {
        $this->assertRejects('rating_not_numeric', $this->cells(['rating' => 'abc']), $this->format('Paper'));
    }

    public function test_out_of_range_rating_is_distinct_from_non_numeric(): void
    {
        $this->assertRejects('rating_out_of_range', $this->cells(['rating' => '7']), $this->format('Paper'));
    }

    public function test_non_half_step_rating_is_out_of_range(): void
    {
        $this->assertRejects('rating_out_of_range', $this->cells(['rating' => '3.7']), $this->format('Paper'));
    }

    public function test_unparseable_date_is_rejected(): void
    {
        $this->assertRejects('date_parse_failed', $this->cells(['date_read' => '01-2024-03']), $this->format('Paper'));
    }

    /**
     * @dataProvider dateFormats
     */
    public function test_each_accepted_date_format_parses(string $raw, string $expected): void
    {
        $row = $this->service->validateRow($this->cells(['date_read' => $raw]), $this->format('Paper'));

        $this->assertSame($expected, $row->dateRead->format('Y-m-d'));
    }

    public static function dateFormats(): array
    {
        return [
            'iso' => ['2024-03-01', '2024-03-01'],
            'no leading zeros' => ['3/1/2024', '2024-03-01'],
            'leading zeros' => ['03/01/2024', '2024-03-01'],
        ];
    }
}
