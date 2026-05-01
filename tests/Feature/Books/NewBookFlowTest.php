<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewBookFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_or_get_by_title_returns_existing_book_with_user_scoped_reads(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create(['title' => 'The Stand', 'slug' => 'the-stand']);
        Version::factory()->for($book, 'book')->withReadInstances(2, ['user_id' => $user->user_id])->create();

        $otherUser = \App\Models\User::factory()->create();
        Version::factory()->for($book, 'book')->withReadInstances(3, ['user_id' => $otherUser->user_id])->create();

        $response = $this->postJson('/api/create-book/title', ['title' => 'The Stand']);

        $response->assertOk()->assertJsonPath('exists', true);
        $this->assertSame($book->book_id, $response->json('book.book_id'));

        $reads = collect($response->json('book.versions'))->flatMap(fn ($v) => $v['read_instances'] ?? []);
        $this->assertCount(2, $reads, 'read_instances should be scoped to the authenticated user');
    }

    public function test_create_or_get_by_title_returns_proposed_payload_for_new_title(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/create-book/title', ['title' => 'A Brand New Title!']);

        $response->assertOk()->assertJsonPath('exists', false);
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

        // Either a 422 with validation errors or a 200 with success:false + a rating-related reason.
        // Bulk-upload rejects rating>5 with reason_code rating_out_of_range; this endpoint should too.
        $status = $response->status();
        if ($status === 422) {
            $this->assertTrue(true);
        } else {
            $response->assertStatus(200)->assertJsonPath('success', false);
            $body = json_encode($response->json());
            $this->assertMatchesRegularExpression('/rating/i', $body, 'response should mention rating as the failure reason');
        }
        $this->assertDatabaseMissing('books', ['slug' => 'out-of-range']);
    }

    public function test_complete_book_creation_generates_unique_slug_when_title_collides(): void
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
        $this->assertDatabaseHas('books', ['title' => 'Dune', 'slug' => 'dune-1']);
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

    public function test_complete_book_creation_rolls_back_on_failure(): void
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

        $response->assertStatus(200)->assertJsonPath('success', false);
        // Don't assert a 4xx — the controller emits 200-on-failure today; we're not dictating
        // the new contract here, just blocking the trace leak from being silently accepted.
        $response->assertJsonMissing(['trace']);
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
