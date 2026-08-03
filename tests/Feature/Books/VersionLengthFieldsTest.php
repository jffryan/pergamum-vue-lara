<?php

namespace Tests\Feature\Books;

use App\Models\Book;
use App\Models\Format;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which length fields a version stores is decided by its format's capability
 * flags, not by matching the format's name or id.
 *
 * The old rule was `$format->name == 'Audiobook'` / `'Paper'` in
 * `BookController::prepareVersions`. It had two failure modes this pins:
 * a payload omitting `audio_runtime` for a format that fell through to the
 * `else` branch threw `Undefined array key`, and `versions.page_count` was
 * NOT NULL, so a format with no page count could not be stored at all.
 */
class VersionLengthFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function createPayload(Format $format, array $version): array
    {
        return [
            'book' => [
                'book' => [
                    'title' => 'Ancillary Justice',
                    'genres' => ['parsed' => []],
                ],
                'authors' => [['first_name' => 'Ann', 'last_name' => 'Leckie']],
                'versions' => [['format' => $format->format_id, 'nickname' => null] + $version],
            ],
        ];
    }

    /**
     * The undefined-key 500. Any format that wasn't literally named 'Paper'
     * fell to a branch that read `audio_runtime` unconditionally.
     */
    public function test_a_print_version_omitting_audio_runtime_is_accepted(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->print()->create(['name' => 'Hardcover']);

        $this->postJson('/api/books', $this->createPayload($format, ['page_count' => 320]))
            ->assertOk();

        $version = Book::where('slug', 'ancillary-justice')->firstOrFail()->versions->first();

        $this->assertSame(320, $version->page_count);
        $this->assertNull($version->audio_runtime);
    }

    /**
     * The integrity-constraint 500. An audiobook has no page count, and now
     * stores none rather than the 0 the NOT NULL column used to force.
     */
    public function test_an_audio_version_omitting_page_count_is_accepted(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->audio()->create(['name' => 'Audiobook']);

        $this->postJson('/api/books', $this->createPayload($format, ['audio_runtime' => 540]))
            ->assertOk();

        $version = Book::where('slug', 'ancillary-justice')->firstOrFail()->versions->first();

        $this->assertNull($version->page_count);
        $this->assertSame(540, $version->audio_runtime);
    }

    /**
     * A value the format doesn't carry is dropped rather than trusted, so a
     * client sending both can't smuggle a page count onto an audiobook — which
     * `estimatedTotalPagesByYear` would then count on top of its runtime.
     */
    public function test_a_field_the_format_does_not_expect_is_not_stored(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->audio()->create(['name' => 'Audiobook']);

        $this->postJson('/api/books', $this->createPayload($format, [
            'page_count' => 320,
            'audio_runtime' => 540,
        ]))->assertOk();

        $version = Book::where('slug', 'ancillary-justice')->firstOrFail()->versions->first();

        $this->assertNull($version->page_count);
        $this->assertSame(540, $version->audio_runtime);
    }

    /**
     * Re-formatting an existing version has to clear what the new format
     * doesn't carry, or the row keeps a runtime nothing displays and the
     * statistics still sum.
     */
    public function test_changing_a_versions_format_clears_the_field_it_no_longer_carries(): void
    {
        $this->actingAsUser();
        $audio = Format::factory()->audio()->create(['name' => 'Audiobook']);
        $print = Format::factory()->print()->create(['name' => 'Hardcover']);

        $book = Book::factory()->create(['title' => 'Ancillary Sword', 'slug' => 'ancillary-sword']);
        $version = Version::factory()->for($book, 'book')->create([
            'format_id' => $audio->format_id,
            'page_count' => null,
            'audio_runtime' => 540,
        ]);

        $this->putJson("/api/books/{$book->book_id}", [
            'request' => [
                'formData' => [
                    'book' => ['title' => $book->title],
                    'authors' => [],
                    'genres' => [],
                    'readInstances' => [],
                    'versions' => [[
                        'version_id' => $version->version_id,
                        'format' => $print->format_id,
                        'nickname' => null,
                        'page_count' => 320,
                        'audio_runtime' => 540,
                    ]],
                ],
            ],
        ])->assertOk();

        $version->refresh();

        $this->assertSame($print->format_id, $version->format_id);
        $this->assertSame(320, $version->page_count);
        $this->assertNull($version->audio_runtime, 'the runtime must not survive the format change');
    }
}
