<?php

namespace Tests\Feature\Genres;

use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The three ingest doors that attach genres to a book, pinned against one
 * set of name rules.
 *
 * Each takes a different input shape — `PUT /books/{id}` `{genre_id, name}`
 * objects, `POST /create-book` `{name}` objects, and `POST /bulk-upload` a
 * `;`-separated CSV cell — and each used to resolve names its own way:
 * `Genre::firstOrCreate` on the raw value for the forms, a
 * `LOWER(TRIM(name))` match for the importer. They now all route through
 * `GenreService`, so the assertions below are deliberately near-identical
 * across doors: the shapes differ, the resulting genre rows must not. (There
 * was a fourth, `POST /books`, deleted 2026-09-29 once `/create-book` owned
 * creation.)
 *
 * `POST /books/bulk-tag` (plain names, from a listing) joined 2026-10-03 and
 * has its block below; the cross-door test predates it.
 *
 * If a new door appears, it gets a block here. The admin CRUD door is
 * covered by `GenresCrudTest`, and merge by `GenreMergeTest`.
 */
class GenreIngestTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // PUT /api/books/{id} — the edit form. {genre_id, name} objects.
    // ---------------------------------------------------------------

    public function test_update_syncs_genres_by_name(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);
        $book->genres()->attach(Genre::factory()->create(['name' => 'Dropped'])->genre_id);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['name' => 'Space Opera'],
        ]))->assertOk();

        $this->assertSame(['Space Opera'], $book->fresh()->genres->pluck('name')->all());
    }

    public function test_update_collapses_surrounding_and_internal_whitespace(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['name' => "  science   fiction \n"],
        ]))->assertOk();

        $this->assertDatabaseHas('genres', ['name' => 'science fiction']);
        $this->assertSame(1, Genre::count());
    }

    public function test_update_prefers_a_named_genre_id_over_the_submitted_name(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);
        $existing = Genre::factory()->create(['name' => 'Space Opera']);

        // The edit form renders the stored name into a text input; an id that
        // arrives alongside an edited name is a rename attempt, and renaming
        // is the admin surface's job. The id wins and the name is ignored.
        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['genre_id' => $existing->genre_id, 'name' => 'Something Else'],
        ]))->assertOk();

        $this->assertSame(1, Genre::count());
        $this->assertDatabaseHas('genres', ['genre_id' => $existing->genre_id, 'name' => 'Space Opera']);
        $this->assertSame($existing->genre_id, $book->fresh()->genres->first()->genre_id);
    }

    public function test_update_falls_back_to_the_name_when_the_genre_id_is_unknown(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['genre_id' => 9999, 'name' => 'Space Opera'],
        ]))->assertOk();

        $this->assertDatabaseHas('genres', ['name' => 'Space Opera']);
        $this->assertSame(['Space Opera'], $book->fresh()->genres->pluck('name')->all());
    }

    public function test_update_dedupes_an_id_and_a_name_pointing_at_the_same_genre(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);
        $existing = Genre::factory()->create(['name' => 'Space Opera']);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['genre_id' => $existing->genre_id, 'name' => 'Space Opera'],
            ['name' => 'space opera'],
        ]))->assertOk();

        $this->assertSame(1, Genre::count());
        $this->assertCount(1, $book->fresh()->genres, 'the pivot must not carry the same genre twice');
    }

    public function test_update_drops_blank_genre_names(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['name' => 'Space Opera'],
            ['name' => '   '],
            ['genre_id' => null, 'name' => null],
        ]))->assertOk();

        $this->assertSame(1, Genre::count());
        $this->assertSame(['Space Opera'], $book->fresh()->genres->pluck('name')->all());
    }

    // ---------------------------------------------------------------
    // POST /api/create-book — the guided flow. {name} objects.
    // ---------------------------------------------------------------

    public function test_complete_creation_attaches_genres_by_name(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/create-book', $this->completePayload([
            ['name' => 'Science Fiction'],
        ]))->assertOk();

        $book = Book::where('slug', 'project-hail-mary')->firstOrFail();
        $this->assertSame(['Science Fiction'], $book->genres->pluck('name')->all());
    }

    public function test_complete_creation_collapses_surrounding_and_internal_whitespace(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/create-book', $this->completePayload([
            ['name' => "  science   fiction \n"],
        ]))->assertOk();

        $this->assertDatabaseHas('genres', ['name' => 'science fiction']);
        $this->assertSame(1, Genre::count());
    }

    public function test_complete_creation_reuses_an_existing_genre_regardless_of_case(): void
    {
        $this->actingAsUser();
        $existing = Genre::factory()->create(['name' => 'essays']);

        $this->postJson('/api/create-book', $this->completePayload([
            ['name' => 'Essays'],
        ]))->assertOk();

        $this->assertSame(1, Genre::count());
        $book = Book::where('slug', 'project-hail-mary')->firstOrFail();
        $this->assertSame($existing->genre_id, $book->genres->first()->genre_id);
    }

    public function test_complete_creation_dedupes_names_within_one_payload(): void
    {
        $this->actingAsUser();

        // Three spellings that differ by case and by whitespace only. None of
        // them exists yet, so nothing reaches the database to be compared by
        // the collation — the dedupe has to happen in PHP.
        $this->postJson('/api/create-book', $this->completePayload([
            ['name' => 'Science Fiction'],
            ['name' => ' science fiction '],
            ['name' => "SCIENCE   fiction\n"],
        ]))->assertOk();

        $book = Book::where('slug', 'project-hail-mary')->firstOrFail();
        $this->assertSame(1, Genre::count());
        $this->assertCount(1, $book->genres, 'the pivot must not carry the same genre twice');
    }

    public function test_complete_creation_keeps_names_that_differ_by_more_than_whitespace(): void
    {
        $this->actingAsUser();

        // Collapsing internal whitespace is not the same as removing it:
        // "Fan\ttasy" is "Fan tasy", which is not "Fantasy".
        $this->postJson('/api/create-book', $this->completePayload([
            ['name' => 'Fantasy'],
            ['name' => "Fan\ttasy"],
        ]))->assertOk();

        $this->assertSame(2, Genre::count());
        $this->assertDatabaseHas('genres', ['name' => 'Fantasy']);
        $this->assertDatabaseHas('genres', ['name' => 'Fan tasy']);
    }

    public function test_complete_creation_drops_blank_genre_names(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/create-book', $this->completePayload([
            ['name' => 'Fantasy'],
            ['name' => '   '],
            ['name' => ''],
            ['name' => null],
        ]))->assertOk();

        $this->assertSame(1, Genre::count(), 'a whitespace-only name is not a genre');
        $this->assertDatabaseMissing('genres', ['name' => '']);
    }

    // ---------------------------------------------------------------
    // POST /api/bulk-upload — the CSV importer. `;`-separated names.
    //
    // The door easiest to forget: not a form, no FormRequest, and it used to
    // match on `LOWER(TRIM(name))` — trimming but not collapsing internal
    // whitespace, so "science  fiction" from a CSV was a different genre from
    // the one the book form would have made.
    // ---------------------------------------------------------------

    public function test_bulk_upload_collapses_whitespace_and_reuses_existing_genres(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Paper']);
        $existing = Genre::factory()->create(['name' => 'Fantasy']);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $this->csvFile([
                'title' => 'The Hobbit',
                'authors' => 'J. R. R.|Tolkien',
                'format' => $format->name,
                'page_count' => '310',
                'genres' => '  science   fiction ; fantasy ;   ',
            ]),
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.succeeded'));

        $book = Book::where('slug', 'the-hobbit')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['Fantasy', 'science fiction'],
            $book->genres->pluck('name')->all(),
            'the CSV door must land on the same rows the book forms would',
        );
        $this->assertSame($existing->genre_id, $book->genres->firstWhere('name', 'Fantasy')->genre_id);
        $this->assertSame(2, Genre::count(), 'the blank third entry is not a genre');
    }

    // ---------------------------------------------------------------
    // POST /api/books/bulk-tag — tagging from a listing. Plain names.
    // Its endpoint contract is in `Tests\Feature\Books\BulkTagBooksTest`.
    // ---------------------------------------------------------------

    public function test_bulk_tag_collapses_whitespace_and_reuses_an_existing_genre_regardless_of_case(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);
        $existing = Genre::factory()->create(['name' => 'space opera']);

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'names' => ["  SPACE   opera \n"],
        ])->assertOk();

        $this->assertSame(1, Genre::count());
        $this->assertSame($existing->genre_id, $book->fresh()->genres->first()->genre_id);
    }

    public function test_bulk_tag_dedupes_names_within_one_payload(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Ancillary Justice', 'slug' => 'ancillary-justice']);

        $this->postJson('/api/books/bulk-tag', [
            'book_ids' => [$book->book_id],
            'names' => ['Science Fiction', ' science fiction '],
        ])->assertOk();

        $this->assertSame(1, Genre::count());
        $this->assertCount(1, $book->fresh()->genres, 'the pivot must not carry the same genre twice');
    }

    // ---------------------------------------------------------------
    // Cross-door: the whole point of the consolidation.
    // ---------------------------------------------------------------

    public function test_all_three_doors_land_on_the_same_genre_row(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Paper']);

        // The create flow makes it, with the messiest spelling of the three.
        $this->postJson('/api/create-book', $this->completePayload([
            ['name' => '  magical   realism '],
        ]))->assertOk();
        $created = Genre::where('name', 'magical realism')->firstOrFail();

        // The edit form spells it differently and must find the same row.
        $book = Book::factory()->create(['title' => 'Beloved', 'slug' => 'beloved']);
        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['name' => 'MAGICAL REALISM'],
        ]))->assertOk();

        // The importer spells it differently again.
        $this->postJson('/api/bulk-upload', [
            'csv_file' => $this->csvFile([
                'title' => 'One Hundred Years of Solitude',
                'authors' => 'Gabriel|Garcia Marquez',
                'format' => $format->name,
                'page_count' => '417',
                'genres' => 'Magical  Realism',
            ]),
        ])->assertOk();

        $this->assertSame(1, Genre::count(), 'three doors, three spellings, one genre row');
        $this->assertSame($created->genre_id, $book->fresh()->genres->first()->genre_id);
        $this->assertSame(
            $created->genre_id,
            Book::where('slug', 'one-hundred-years-of-solitude')->firstOrFail()->genres->first()->genre_id,
        );
    }

    // ---------------------------------------------------------------
    // Payload builders — each door's envelope, genres swapped in.
    // ---------------------------------------------------------------

    /** @param  array<int, array<string, mixed>>  $genres */
    private function updatePayload(Book $book, array $genres): array
    {
        return [
            'book' => ['title' => $book->title],
            'authors' => [],
            'genres' => $genres,
            'readInstances' => [],
            'versions' => [],
        ];
    }

    /**
     * One-row CSV in the importer's column order — see `CsvContract`.
     *
     * @param  array<string, string>  $cells
     */
    private function csvFile(array $cells): UploadedFile
    {
        $headers = ['title', 'authors', 'format', 'page_count', 'audio_runtime', 'version_nickname', 'genres', 'date_read', 'rating'];

        $path = tempnam(sys_get_temp_dir(), 'csv');
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);
        fputcsv($handle, array_map(fn ($column) => $cells[$column] ?? '', $headers));
        fclose($handle);

        return new UploadedFile($path, 'genres.csv', 'text/csv', null, true);
    }

    /** @param  array<int, array<string, mixed>>  $genres */
    private function completePayload(array $genres): array
    {
        $format = Format::factory()->create();

        return [
            'bookData' => [
                'book' => ['title' => 'Project Hail Mary', 'slug' => 'project-hail-mary'],
                'authors' => [['first_name' => 'Andy', 'last_name' => 'Weir']],
                'genres' => $genres,
                'versions' => [
                    [
                        'format' => ['format_id' => $format->format_id],
                        'page_count' => 476,
                        'audio_runtime' => null,
                        'nickname' => null,
                    ],
                ],
                'read_instances' => [],
            ],
        ];
    }
}
