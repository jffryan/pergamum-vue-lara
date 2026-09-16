<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewBookFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The title step can't decide identity on its own — it lists every
     * same-title book with its authors and the SPA asks the user.
     */
    public function test_create_or_get_by_title_lists_every_same_title_book_with_authors(): void
    {
        $this->actingAsUser();
        $plath = Book::factory()->create(['title' => 'Ariel', 'slug' => 'ariel']);
        $plath->authors()->attach(Author::factory()->create(['first_name' => 'Sylvia', 'last_name' => 'Plath'])->author_id, ['author_ordinal' => 1]);
        $rodo = Book::factory()->create(['title' => 'Ariel', 'slug' => 'ariel-rodo']);
        $rodo->authors()->attach(Author::factory()->create(['first_name' => 'José Enrique', 'last_name' => 'Rodó'])->author_id, ['author_ordinal' => 1]);
        Book::factory()->create(['title' => "Ariel's Gift", 'slug' => 'ariel-s-gift']);

        $response = $this->postJson('/api/create-book/title', ['title' => 'Ariel']);

        $response->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('book.slug', 'ariel')
            ->assertJsonCount(2, 'matches')
            ->assertJsonPath('matches.0.slug', 'ariel')
            ->assertJsonPath('matches.0.authors.0.last_name', 'Plath')
            ->assertJsonPath('matches.1.slug', 'ariel-rodo')
            ->assertJsonPath('matches.1.authors.0.last_name', 'Rodó');
    }

    public function test_create_or_get_by_title_returns_proposed_payload_for_new_title(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/create-book/title', ['title' => 'A Brand New Title!']);

        $response->assertOk()->assertJsonPath('exists', false)->assertJsonPath('matches', []);
        $this->assertSame('A Brand New Title!', $response->json('book.title'));
        $this->assertSame('a-brand-new-title', $response->json('book.slug'));
        $this->assertDatabaseMissing('books', ['slug' => 'a-brand-new-title']);
    }

    public function test_complete_book_creation_persists_full_graph(): void
    {
        $user = $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Hardcover']);

        $payload = [
            'bookData' => [
                'book' => ['title' => 'Project Hail Mary', 'slug' => 'project-hail-mary'],
                'authors' => [
                    ['first_name' => 'Andy', 'last_name' => 'Weir'],
                ],
                'genres' => [
                    ['name' => 'Science Fiction'],
                ],
                'versions' => [
                    [
                        'format' => ['format_id' => $format->format_id],
                        'page_count' => 476,
                        'audio_runtime' => null,
                        'nickname' => 'first edition',
                    ],
                ],
                'read_instances' => [
                    ['date_read' => '2026-01-15', 'rating' => 4],
                ],
            ],
        ];

        $response = $this->postJson('/api/create-book', $payload);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('books', ['title' => 'Project Hail Mary', 'slug' => 'project-hail-mary']);
        $book = Book::where('slug', 'project-hail-mary')->firstOrFail();
        $this->assertCount(1, $book->authors);
        $this->assertCount(1, $book->genres);
        $this->assertCount(1, $book->versions);
        $this->assertSame(476, $book->versions->first()->page_count);
        $this->assertDatabaseHas('read_instances', [
            'book_id' => $book->book_id,
            'user_id' => $user->user_id,
            'date_read' => '2026-01-15',
            'rating' => 8, // ReadInstance::setRatingAttribute doubles input (out-of-5 → out-of-10)
        ]);
    }

    public function test_complete_book_creation_rejects_rating_out_of_range(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create(['name' => 'Hardcover']);

        $payload = [
            'bookData' => [
                'book' => ['title' => 'Out Of Range', 'slug' => 'out-of-range'],
                'authors' => [['first_name' => 'Some', 'last_name' => 'Author']],
                'genres' => [],
                'versions' => [[
                    'format' => ['format_id' => $format->format_id],
                    'page_count' => 100,
                    'audio_runtime' => null,
                    'nickname' => null,
                ]],
                'read_instances' => [
                    ['date_read' => '2026-01-15', 'rating' => 9],
                ],
            ],
        ];

        $response = $this->postJson('/api/create-book', $payload);

        // Matches how bulk upload reports the same rejection.
        $response->assertStatus(422)
            ->assertJsonPath('reason_code', 'rating_out_of_range')
            ->assertJsonValidationErrors('bookData.read_instances.0.rating');

        $this->assertDatabaseMissing('books', ['slug' => 'out-of-range']);
    }

    /**
     * The user chose "create a different book" on the confirmation screen,
     * so this is a second book by design — filed under its author's surname.
     */
    public function test_complete_book_creation_files_a_colliding_title_under_the_authors_surname(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create();
        Book::factory()->create(['title' => 'Dune', 'slug' => 'dune']);

        $payload = [
            'bookData' => [
                'book' => ['title' => 'Dune', 'slug' => 'a-tampered-slug'],
                'authors' => [['first_name' => 'Frank', 'last_name' => 'Herbert']],
                'genres' => [],
                'versions' => [[
                    'format' => ['format_id' => $format->format_id],
                    'page_count' => 700,
                    'audio_runtime' => null,
                    'nickname' => null,
                ]],
                'read_instances' => [],
            ],
        ];

        $response = $this->postJson('/api/create-book', $payload);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('books', ['title' => 'Dune', 'slug' => 'dune-herbert']);
    }

    public function test_complete_book_creation_reuses_existing_authors_via_slug(): void
    {
        $this->actingAsUser();
        $format = Format::factory()->create();
        $existing = Author::create(['first_name' => 'Ursula', 'last_name' => 'Le Guin', 'slug' => 'ursula-le-guin']);

        $payload = [
            'bookData' => [
                'book' => ['title' => 'A Wizard of Earthsea', 'slug' => 'a-wizard-of-earthsea'],
                'authors' => [['first_name' => 'Ursula', 'last_name' => 'Le Guin']],
                'genres' => [],
                'versions' => [[
                    'format' => ['format_id' => $format->format_id],
                    'page_count' => 200,
                    'audio_runtime' => null,
                    'nickname' => null,
                ]],
                'read_instances' => [],
            ],
        ];

        $this->postJson('/api/create-book', $payload)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, Author::where('slug', 'ursula-le-guin')->count(), 'should not duplicate existing author');
        $book = Book::where('slug', 'a-wizard-of-earthsea')->firstOrFail();
        $this->assertTrue($book->authors->contains('author_id', $existing->author_id));
    }

    /**
     * A format id that names nothing is a bad request, and is now rejected
     * before the transaction opens. It used to throw inside the try, roll
     * back, and report the failure as a 200 with `success: false` — which the
     * SPA had to read the body to notice.
     */
    public function test_complete_book_creation_rejects_an_unknown_format_and_writes_nothing(): void
    {
        $this->actingAsUser();

        $payload = [
            'bookData' => [
                'book' => ['title' => 'Will Not Persist', 'slug' => 'will-not-persist'],
                'authors' => [['first_name' => 'Some', 'last_name' => 'One']],
                'genres' => [],
                'versions' => [[
                    'format' => ['format_id' => 999999], // non-existent format
                    'page_count' => 100,
                    'audio_runtime' => null,
                    'nickname' => null,
                ]],
                'read_instances' => [],
            ],
        ];

        $response = $this->postJson('/api/create-book', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('bookData.versions.0.format.format_id');
        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertDatabaseMissing('books', ['slug' => 'will-not-persist']);
        $this->assertDatabaseMissing('authors', ['first_name' => 'Some', 'last_name' => 'One']);
    }

    public function test_create_authors_returns_existing_author_for_known_slug(): void
    {
        $this->actingAsUser();
        $existing = Author::create(['first_name' => 'Brandon', 'last_name' => 'Sanderson', 'slug' => 'brandon-sanderson']);

        $response = $this->postJson('/api/create-authors', [
            'authorsData' => [
                ['name' => 'Brandon Sanderson', 'first_name' => 'Brandon', 'last_name' => 'Sanderson'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame($existing->author_id, $response->json('authors.0.author_id'));
    }

    public function test_create_authors_returns_to_be_created_payload_for_unknown_author(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/create-authors', [
            'authorsData' => [
                ['name' => 'New Person', 'first_name' => 'New', 'last_name' => 'Person'],
            ],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('authors.0.author_id'));
        $this->assertSame('new-person', $response->json('authors.0.slug'));
        $this->assertDatabaseMissing('authors', ['slug' => 'new-person']);
    }
}
