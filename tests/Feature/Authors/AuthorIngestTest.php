<?php

namespace Tests\Feature\Authors;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The four ingest doors that attach authors to a book, pinned against one set
 * of name rules.
 *
 * Each takes a different input shape — `POST /books` under `book.authors`,
 * `PUT /books/{id}` under a top-level `authors`, `POST /create-book` under
 * `bookData.authors`, and `POST /bulk-upload` as a `;`-separated CSV cell of
 * `First|Last` entries — and each used to answer two questions its own way:
 *
 *   1. **Is a last name required?** The three book forms said yes; the CSV
 *      importer said "one of the two". So mononyms and organizations (Plato,
 *      Aristotle, National Geographic) could only enter by import, and once
 *      in, any later edit of a book they were on 422'd on an author the user
 *      never touched. The importer's rule is now everyone's — see
 *      `App\Http\Requests\Concerns\ValidatesAuthorNames`.
 *   2. **What happens on attach?** The importer deduped against the book's
 *      current authors and continued `author_ordinal` from the max; the three
 *      forms called `attach($ids)` with no pivot data, so every author sat at
 *      the column default of 1 and `authors[0]` — the name every book row
 *      renders and the library sorts on — was insert order. The importer's
 *      semantics are now everyone's too, via `AuthorService::attachToBook`.
 *
 * The assertions below are deliberately near-identical across doors: the
 * shapes differ, the resulting author rows and pivot ordinals must not. A
 * fifth door gets a block here.
 */
class AuthorIngestTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // POST /api/books — the legacy create form.
    // ---------------------------------------------------------------

    public function test_create_accepts_a_mononym(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/books', $this->createPayload([
            ['first_name' => 'Plato', 'last_name' => ''],
        ]))->assertOk();

        $author = Author::where('slug', 'plato')->firstOrFail();
        $this->assertSame('Plato', $author->first_name);
        $this->assertSame('', $author->last_name);
    }

    public function test_create_rejects_an_author_with_neither_name(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/books', $this->createPayload([
            ['first_name' => '', 'last_name' => ''],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'author_name_required');

        $this->assertSame(0, Author::count());
    }

    public function test_create_collapses_whitespace_in_both_halves(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/books', $this->createPayload([
            ['first_name' => "  Ursula   K.  \n", 'last_name' => ' Le  Guin '],
        ]))->assertOk();

        $this->assertDatabaseHas('authors', [
            'first_name' => 'Ursula K.',
            'last_name' => 'Le Guin',
        ]);
    }

    public function test_create_numbers_co_authors_in_input_order(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/books', $this->createPayload([
            ['first_name' => 'Terry', 'last_name' => 'Pratchett'],
            ['first_name' => 'Neil', 'last_name' => 'Gaiman'],
        ]))->assertOk();

        $book = Book::where('slug', 'a-new-hope')->firstOrFail();

        // Ordinal, not insert order, is what makes `authors[0]` mean
        // "primary author" — the create doors used to leave every row at the
        // column default of 1.
        $this->assertSame(
            ['Pratchett' => 1, 'Gaiman' => 2],
            $this->ordinalsByLastName($book),
        );
    }

    // ---------------------------------------------------------------
    // PUT /api/books/{id} — the edit form.
    // ---------------------------------------------------------------

    public function test_update_accepts_a_mononym_on_a_new_author(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Meditations', 'slug' => 'meditations']);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['first_name' => 'Aristotle', 'last_name' => ''],
        ]))->assertOk();

        $this->assertDatabaseHas('authors', ['slug' => 'aristotle', 'first_name' => 'Aristotle']);
        $this->assertCount(1, $book->fresh()->authors);
    }

    /**
     * The bug this whole change came out of: an author imported without a last
     * name made every book they were on uneditable, because the edit form
     * round-trips the existing authors back into the payload.
     */
    public function test_update_saves_a_book_whose_existing_author_has_no_last_name(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Republic', 'slug' => 'republic']);
        $plato = Author::factory()->create([
            'first_name' => 'Plato',
            'last_name' => '',
            'slug' => 'plato',
        ]);
        $book->authors()->attach($plato->author_id, ['author_ordinal' => 1]);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['author_id' => $plato->author_id, 'first_name' => 'Plato', 'last_name' => ''],
        ]))->assertOk();

        $this->assertSame(1, Author::count());
        $this->assertSame('', $plato->fresh()->last_name);
    }

    public function test_update_rejects_an_author_with_neither_name(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Untitled', 'slug' => 'untitled']);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['first_name' => ' ', 'last_name' => null],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'author_name_required');
    }

    public function test_update_appends_a_co_author_after_the_existing_one(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Good Omens', 'slug' => 'good-omens']);
        $first = Author::factory()->create(['first_name' => 'Terry', 'last_name' => 'Pratchett']);
        $book->authors()->attach($first->author_id, ['author_ordinal' => 1]);

        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['author_id' => $first->author_id, 'first_name' => 'Terry', 'last_name' => 'Pratchett'],
            ['first_name' => 'Neil', 'last_name' => 'Gaiman'],
        ]))->assertOk();

        $this->assertSame(
            ['Pratchett' => 1, 'Gaiman' => 2],
            $this->ordinalsByLastName($book->fresh()),
        );
    }

    // ---------------------------------------------------------------
    // POST /api/create-book — the multi-step create flow.
    // ---------------------------------------------------------------

    public function test_complete_creation_accepts_an_organization_as_the_author(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/create-book', $this->completePayload([
            ['first_name' => 'National Geographic', 'last_name' => ''],
        ]))->assertOk();

        $this->assertDatabaseHas('authors', [
            'slug' => 'national-geographic',
            'first_name' => 'National Geographic',
            'last_name' => '',
        ]);
    }

    public function test_complete_creation_rejects_an_author_with_neither_name(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/create-book', $this->completePayload([
            ['first_name' => '', 'last_name' => '   '],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'author_name_required');

        $this->assertSame(0, Book::count());
    }

    // ---------------------------------------------------------------
    // POST /api/bulk-upload — the CSV importer. The door that was right all
    // along, and the one with no FormRequest to keep it honest.
    // ---------------------------------------------------------------

    public function test_bulk_upload_accepts_a_mononym_entry(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Paper']);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $this->csvFile([
                'title' => 'Symposium',
                'authors' => 'Plato|',
                'format' => $format->name,
                'page_count' => '210',
            ]),
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('summary.succeeded'));
        $this->assertDatabaseHas('authors', ['slug' => 'plato', 'first_name' => 'Plato']);
    }

    public function test_bulk_upload_still_rejects_an_entry_with_neither_name(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Paper']);

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => $this->csvFile([
                'title' => 'Anonymous',
                'authors' => '|',
                'format' => $format->name,
                'page_count' => '210',
            ]),
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.succeeded'));
        $this->assertSame('author_entry_malformed', $response->json('results.0.reason_code'));
    }

    // ---------------------------------------------------------------
    // Cross-door: the whole point of the consolidation.
    // ---------------------------------------------------------------

    public function test_all_four_doors_land_on_the_same_author_row(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Paper']);

        // Door 1 creates her, with the messiest spelling of the four.
        $this->postJson('/api/books', $this->createPayload([
            ['first_name' => '  Ursula   K. ', 'last_name' => ' Le Guin '],
        ]))->assertOk();
        $created = Author::where('slug', 'ursula-k-le-guin')->firstOrFail();

        // Door 3.
        $this->postJson('/api/create-book', $this->completePayload([
            ['first_name' => 'Ursula K.', 'last_name' => 'Le Guin'],
        ]))->assertOk();

        // Door 2.
        $book = Book::factory()->create(['title' => 'The Dispossessed', 'slug' => 'the-dispossessed']);
        $this->putJson("/api/books/{$book->book_id}", $this->updatePayload($book, [
            ['first_name' => 'Ursula K.', 'last_name' => 'Le  Guin'],
        ]))->assertOk();

        // Door 4.
        $this->postJson('/api/bulk-upload', [
            'csv_file' => $this->csvFile([
                'title' => 'The Lathe of Heaven',
                'authors' => ' Ursula K. | Le Guin ',
                'format' => $format->name,
                'page_count' => '184',
            ]),
        ])->assertOk();

        $this->assertSame(1, Author::count(), 'four doors, four spellings, one author row');
        $this->assertSame(4, $created->fresh()->books()->count());
    }

    // ---------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------

    /** @return array<string, int> */
    private function ordinalsByLastName(Book $book): array
    {
        return $book->authors()
            ->get()
            ->mapWithKeys(fn ($author) => [$author->last_name => $author->pivot->author_ordinal])
            ->all();
    }

    /** @param  array<int, array<string, mixed>>  $authors */
    private function createPayload(array $authors): array
    {
        $format = Format::factory()->create();

        return [
            'book' => [
                'book' => [
                    'title' => 'A New Hope',
                    'genres' => ['parsed' => []],
                ],
                'authors' => $authors,
                'versions' => [
                    ['format' => $format->format_id, 'page_count' => 320, 'nickname' => null, 'audio_runtime' => null],
                ],
            ],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $authors */
    private function updatePayload(Book $book, array $authors): array
    {
        return [
            'book' => ['title' => $book->title],
            'authors' => $authors,
            'genres' => [],
            'readInstances' => [],
            'versions' => [],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $authors */
    private function completePayload(array $authors): array
    {
        $format = Format::factory()->create();

        return [
            'bookData' => [
                'book' => ['title' => 'Project Hail Mary', 'slug' => 'project-hail-mary'],
                'authors' => $authors,
                'genres' => [],
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

        return new UploadedFile($path, 'authors.csv', 'text/csv', null, true);
    }
}
