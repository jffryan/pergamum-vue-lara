<?php

namespace Tests\Feature\Validation;

use App\Models\Book;
use App\Models\Format;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The book write surface answers 422, not 500.
 *
 * Before the FormRequests landed, `BookController` and `NewBookController`
 * called `validate()` exactly zero times and reached into the payload by key
 * (`$bookForm['book']['title']`, `$request['readInstance']['book_id']`). A
 * payload missing any of those keys was an `Undefined array key` — a 500 that
 * told the caller nothing and, on the update path, was caught and returned
 * with its exception message in the body.
 *
 * These tests pin the failure mode rather than any particular message: the
 * status is 422, the offending field is named, and nothing is written.
 */
class BookWriteValidationTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // POST /api/create-book
    //
    // Unknown formats, author names and out-of-range ratings are pinned in
    // `NewBookFlowTest` and `AuthorIngestTest`; these are the gaps.
    // ---------------------------------------------------------------

    public function test_create_without_a_title_is_a_422(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/create-book', ['bookData' => ['book' => []]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bookData.book.title');

        $this->assertSame(0, Book::count());
    }

    public function test_create_without_genres_is_accepted(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->print()->create();

        $this->postJson('/api/create-book', [
            'bookData' => [
                'book' => ['title' => 'Ungenred'],
                'authors' => [['first_name' => 'A', 'last_name' => 'Writer']],
                'versions' => [['format' => ['format_id' => $format->format_id], 'page_count' => 100]],
            ],
        ])->assertOk();

        $book = Book::where('slug', 'ungenred')->firstOrFail();
        $this->assertCount(0, $book->genres);
        $this->assertCount(1, $book->versions);
    }

    /**
     * `NewBookFlowTest` pins a rating above the scale; this is one inside it
     * but between the half-steps.
     */
    public function test_create_rejects_a_rating_off_the_half_step_scale(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->print()->create();

        $this->postJson('/api/create-book', [
            'bookData' => [
                'book' => ['title' => 'Rated Wrong'],
                'authors' => [['first_name' => 'A', 'last_name' => 'Writer']],
                'versions' => [['format' => ['format_id' => $format->format_id], 'page_count' => 100]],
                'read_instances' => [['date_read' => '2026-01-01', 'rating' => 3.7]],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'rating_out_of_range');

        $this->assertSame(0, Book::count());
    }

    // ---------------------------------------------------------------
    // PUT /api/books/{id}
    // ---------------------------------------------------------------

    public function test_update_without_a_title_is_a_422_not_a_500(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'Keep Me', 'slug' => 'keep-me']);

        $this->putJson("/api/books/{$book->book_id}", ['book' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('book.title');

        $this->assertSame('Keep Me', $book->fresh()->title);
    }

    public function test_update_with_no_body_at_all_is_a_422(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();

        // This was the sharpest edge: `$request->all()['request']['formData']`
        // threw before anything was validated.
        $this->putJson("/api/books/{$book->book_id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('book');
    }

    public function test_update_rejects_an_unknown_format_on_an_existing_version(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->print()->create();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create([
            'format_id' => $format->format_id,
            'page_count' => 100,
        ]);

        $this->putJson("/api/books/{$book->book_id}", [
            'book' => ['title' => $book->title],
            'versions' => [[
                'version_id' => $version->version_id,
                'format' => 999_999,
                'nickname' => null,
                'page_count' => 250,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('versions.0.format');

        // The old code hit `continue` on the unknown format and reported 200,
        // so the edit silently did nothing.
        $this->assertSame(100, $version->fresh()->page_count);
    }

    public function test_update_accepts_a_date_read_in_the_display_format(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $read = ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2024-03-10',
        ]);

        // `Carbon::createFromFormat('Y-m-d', '03/14/2024')` threw, so an m/d/Y
        // date reached the controller as a 500.
        $this->putJson("/api/books/{$book->book_id}", [
            'book' => ['title' => $book->title],
            'readInstances' => [[
                'read_instance_id' => $read->read_instance_id,
                'date_read' => '03/14/2024',
                'rating' => 4,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('read_instances', [
            'read_instance_id' => $read->read_instance_id,
            'date_read' => '2024-03-14',
        ]);
    }

    // ---------------------------------------------------------------
    // POST /api/add-read-instance
    // ---------------------------------------------------------------

    public function test_add_read_instance_without_a_read_instance_key_is_a_422(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/add-read-instance', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('readInstance');
    }

    public function test_add_read_instance_keeps_its_reason_code_for_a_version_from_another_book(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $other = Book::factory()->create();
        $foreignVersion = Version::factory()->for($other, 'book')->create();

        $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => $book->book_id,
                'version_id' => $foreignVersion->version_id,
                'date_read' => '2026-01-01',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'version_book_mismatch');

        $this->assertSame(0, ReadInstance::count());
    }

    public function test_add_read_instance_accepts_a_date_read_in_the_display_format(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => '12/25/2025',
                'rating' => 4.5,
            ],
        ])->assertOk();

        $this->assertDatabaseHas('read_instances', ['date_read' => '2025-12-25']);
    }

    public function test_add_read_instance_writes_exactly_one_row(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        // It used to save through both the book and the version relation.
        // Eloquent deduped the second into an update, but the pair was not
        // atomic; now it is one create inside a transaction.
        $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => '2026-02-02',
                'rating' => 3,
            ],
        ])->assertOk();

        $this->assertSame(1, ReadInstance::count());
        $this->assertSame(1, $version->readInstances()->count());
        $this->assertSame(1, $book->readInstances()->count());
    }
}
