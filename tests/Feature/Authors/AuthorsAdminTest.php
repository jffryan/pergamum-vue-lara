<?php

namespace Tests\Feature\Authors;

use App\Models\Author;
use App\Models\Book;
use App\Services\AuthorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin author surface — `GET /authors`, `PATCH /authors/{author}`,
 * `POST /authors/{author}/merge` — and the book edit form's rename, which
 * goes through the same `AuthorService::rename()`.
 */
class AuthorsAdminTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // GET /api/authors
    // ---------------------------------------------------------------

    public function test_index_lists_authors_in_filing_order_with_book_counts(): void
    {
        $this->actingAsUser();
        $woolf = $this->author('Virginia', 'Woolf');
        $this->author('Aristotle', '');
        $this->author('Hannah', 'Arendt');
        $woolf->books()->attach(Book::factory()->create()->book_id, ['author_ordinal' => 1]);

        $response = $this->getJson('/api/authors')->assertOk();

        $this->assertSame(['Hannah', 'Aristotle', 'Virginia'], array_column($response->json(), 'first_name'));
        $this->assertSame(1, $response->json('2.books_count'));
        $this->assertSame(0, $response->json('0.books_count'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/authors')->assertUnauthorized();
    }

    // ---------------------------------------------------------------
    // PATCH /api/authors/{author}
    // ---------------------------------------------------------------

    public function test_rename_moves_the_slug_with_the_name(): void
    {
        $this->actingAsUser();
        $author = $this->author('Ursula', 'LeGuin');

        $this->patchJson("/api/authors/{$author->author_id}", [
            'first_name' => 'Ursula K.',
            'last_name' => 'Le Guin',
        ])
            ->assertOk()
            ->assertJsonPath('slug', 'ursula-k-le-guin')
            ->assertJsonPath('last_name', 'Le Guin');

        $this->getJson('/api/author/ursula-k-le-guin')->assertOk();
        $this->getJson('/api/author/ursula-leguin')->assertNotFound();
    }

    /**
     * The discrepancy the re-slug exists to prevent: before it, a renamed
     * author kept the old slug, so importing the corrected name missed the
     * row and created a second author.
     */
    public function test_a_renamed_author_is_found_by_the_next_ingest_of_the_new_name(): void
    {
        $this->actingAsUser();
        $author = $this->author('Ursula', 'LeGuin');
        $this->patchJson("/api/authors/{$author->author_id}", [
            'first_name' => 'Ursula',
            'last_name' => 'Le Guin',
        ])->assertOk();

        $book = Book::factory()->create(['title' => 'The Dispossessed', 'slug' => 'the-dispossessed']);
        $this->putJson("/api/books/{$book->book_id}", $this->bookPayload($book, [
            ['first_name' => 'Ursula', 'last_name' => 'Le Guin'],
        ]))->assertOk();

        $this->assertSame(1, Author::count());
        $this->assertTrue($book->authors()->where('authors.author_id', $author->author_id)->exists());
    }

    public function test_rename_normalizes_whitespace(): void
    {
        $this->actingAsUser();
        $author = $this->author('Toni', 'Morison');

        $this->patchJson("/api/authors/{$author->author_id}", [
            'first_name' => '  Toni ',
            'last_name' => "Mor\trison",
        ])->assertOk()->assertJsonPath('last_name', 'Mor rison');
    }

    public function test_rename_accepts_a_single_name(): void
    {
        $this->actingAsUser();
        $author = $this->author('Plato', 'of Athens');

        $this->patchJson("/api/authors/{$author->author_id}", ['first_name' => 'Plato', 'last_name' => ''])
            ->assertOk()
            ->assertJsonPath('slug', 'plato')
            ->assertJsonPath('last_name', '');
    }

    public function test_rename_rejects_an_author_with_neither_name(): void
    {
        $this->actingAsUser();
        $author = $this->author('Plato', '');

        $this->patchJson("/api/authors/{$author->author_id}", ['first_name' => ' ', 'last_name' => null])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'author_name_required');

        $this->assertSame('Plato', $author->fresh()->first_name);
    }

    public function test_rename_onto_another_authors_name_is_a_409_carrying_them(): void
    {
        $this->actingAsUser();
        $keep = $this->author('Octavia', 'Butler');
        $typo = $this->author('Octavia', 'Buttler');

        $this->patchJson("/api/authors/{$typo->author_id}", ['first_name' => 'Octavia', 'last_name' => 'Butler'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'author_name_taken')
            ->assertJsonPath('conflict.author_id', $keep->author_id)
            ->assertJsonStructure(['conflict' => ['books_count']]);

        $this->assertSame('Buttler', $typo->fresh()->last_name);
    }

    public function test_a_cosmetic_rename_keeps_the_slug(): void
    {
        $this->actingAsUser();
        $author = $this->author('bell', 'hooks');

        $this->patchJson("/api/authors/{$author->author_id}", ['first_name' => 'Bell', 'last_name' => 'Hooks'])
            ->assertOk()
            ->assertJsonPath('slug', 'bell-hooks')
            ->assertJsonPath('first_name', 'Bell');
    }

    /**
     * A row the slug migration de-duplicated to `-2` slugs, by name, onto the
     * row it was split from. Fixing its casing mustn't be refused as a
     * collision — only a rename that *changes* the slug is a claim to be
     * someone else.
     */
    public function test_a_deduplicated_author_can_fix_a_typo_without_colliding(): void
    {
        $this->actingAsUser();
        $this->author('John', 'Smith');
        $second = Author::factory()->create(['first_name' => 'john', 'last_name' => 'smith', 'slug' => 'john-smith-2']);

        $this->patchJson("/api/authors/{$second->author_id}", ['first_name' => 'John', 'last_name' => 'Smith'])
            ->assertOk()
            ->assertJsonPath('slug', 'john-smith-2');
    }

    public function test_renaming_to_the_same_name_is_a_no_op(): void
    {
        $this->actingAsUser();
        $author = $this->author('Italo', 'Calvino');

        $this->patchJson("/api/authors/{$author->author_id}", ['first_name' => 'Italo', 'last_name' => 'Calvino'])
            ->assertOk()
            ->assertJsonPath('slug', 'italo-calvino');
    }

    public function test_rename_of_unknown_author_is_404(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/authors/999999', ['first_name' => 'Nobody'])->assertNotFound();
    }

    // ---------------------------------------------------------------
    // PUT /api/books/{id} — the book edit form's rename
    // ---------------------------------------------------------------

    public function test_renaming_through_a_book_moves_the_slug_and_shows_on_every_book(): void
    {
        $this->actingAsUser();
        $author = $this->author('Ursula', 'LeGuin');
        $edited = Book::factory()->create(['title' => 'Lathe of Heaven', 'slug' => 'lathe-of-heaven']);
        $other = Book::factory()->create(['title' => 'Earthsea', 'slug' => 'earthsea']);
        $author->books()->attach([$edited->book_id, $other->book_id], ['author_ordinal' => 1]);

        $this->putJson("/api/books/{$edited->book_id}", $this->bookPayload($edited, [
            ['author_id' => $author->author_id, 'first_name' => 'Ursula K.', 'last_name' => 'Le Guin'],
        ]))->assertOk();

        $this->assertSame('ursula-k-le-guin', $author->fresh()->slug);
        $this->getJson('/api/book/earthsea')
            ->assertJsonPath('authors.0.last_name', 'Le Guin')
            ->assertJsonPath('authors.0.slug', 'ursula-k-le-guin');
    }

    public function test_renaming_through_a_book_onto_another_author_is_a_409_and_writes_nothing(): void
    {
        $this->actingAsUser();
        $this->author('Octavia', 'Butler');
        $typo = $this->author('Octavia', 'Buttler');
        $book = Book::factory()->create(['title' => 'Kindred', 'slug' => 'kindred']);
        $typo->books()->attach($book->book_id, ['author_ordinal' => 1]);

        $payload = $this->bookPayload($book, [
            ['author_id' => $typo->author_id, 'first_name' => 'Octavia', 'last_name' => 'Butler'],
        ]);
        $payload['book']['title'] = 'Kindred: A Novel';

        $this->putJson("/api/books/{$book->book_id}", $payload)
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'author_name_taken');

        $this->assertSame('Buttler', $typo->fresh()->last_name);
        $this->assertSame('Kindred', $book->fresh()->title);
    }

    // ---------------------------------------------------------------
    // POST /api/authors/{author}/merge
    // ---------------------------------------------------------------

    public function test_merge_moves_books_to_the_winner_and_deletes_the_losers(): void
    {
        $this->actingAsUser();
        $keep = $this->author('Octavia', 'Butler');
        $loserA = $this->author('Octavia', 'Buttler');
        $loserB = $this->author('Octavia E.', 'Butler');
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $loserA->books()->attach($bookA->book_id, ['author_ordinal' => 1]);
        $loserB->books()->attach($bookB->book_id, ['author_ordinal' => 1]);

        $this->postJson("/api/authors/{$keep->author_id}/merge", [
            'source_ids' => [$loserA->author_id, $loserB->author_id],
        ])->assertOk()->assertJsonPath('books_count', 2);

        $this->assertDatabaseMissing('authors', ['author_id' => $loserA->author_id]);
        $this->assertDatabaseMissing('authors', ['author_id' => $loserB->author_id]);
        $this->assertDatabaseHas('book_author', ['book_id' => $bookA->book_id, 'author_id' => $keep->author_id]);
        $this->assertDatabaseHas('book_author', ['book_id' => $bookB->book_id, 'author_id' => $keep->author_id]);
    }

    /**
     * Re-pointing rather than re-attaching: a duplicate that was a book's
     * primary author leaves the winner primary, not after the co-authors.
     */
    public function test_merge_keeps_the_losers_position_on_the_book(): void
    {
        $this->actingAsUser();
        $keep = $this->author('Terry', 'Pratchett');
        $loser = $this->author('Terry', 'Prachett');
        $coAuthor = $this->author('Neil', 'Gaiman');
        $book = Book::factory()->create();
        $book->authors()->attach($loser->author_id, ['author_ordinal' => 1]);
        $book->authors()->attach($coAuthor->author_id, ['author_ordinal' => 2]);

        $this->postJson("/api/authors/{$keep->author_id}/merge", ['source_ids' => [$loser->author_id]])->assertOk();

        $this->assertSame(1, (int) DB::table('book_author')
            ->where('book_id', $book->book_id)
            ->where('author_id', $keep->author_id)
            ->value('author_ordinal'));
    }

    public function test_a_book_crediting_both_keeps_one_row_at_the_better_position(): void
    {
        $this->actingAsUser();
        $keep = $this->author('Terry', 'Pratchett');
        $loser = $this->author('Terry', 'Prachett');
        $book = Book::factory()->create();
        $book->authors()->attach($loser->author_id, ['author_ordinal' => 1]);
        $book->authors()->attach($keep->author_id, ['author_ordinal' => 2]);

        $this->postJson("/api/authors/{$keep->author_id}/merge", ['source_ids' => [$loser->author_id]])
            ->assertOk()
            ->assertJsonPath('books_count', 1);

        $rows = DB::table('book_author')->where('book_id', $book->book_id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($keep->author_id, (int) $rows[0]->author_id);
        $this->assertSame(1, (int) $rows[0]->author_ordinal);
    }

    public function test_merging_an_author_into_itself_is_rejected(): void
    {
        $this->actingAsUser();
        $keep = $this->author('Terry', 'Pratchett');

        $this->postJson("/api/authors/{$keep->author_id}/merge", ['source_ids' => [$keep->author_id]])
            ->assertStatus(422);

        $this->assertDatabaseHas('authors', ['author_id' => $keep->author_id]);
    }

    public function test_merge_rejects_unknown_source_ids(): void
    {
        $this->actingAsUser();
        $keep = $this->author('Terry', 'Pratchett');

        $this->postJson("/api/authors/{$keep->author_id}/merge", ['source_ids' => [999999]])
            ->assertStatus(422);
    }

    /**
     * The rename → 409 → merge handoff the admin screen offers, end to end:
     * after the merge, the corrected name is free to be the winner's.
     */
    public function test_a_refused_rename_can_be_resolved_by_merging(): void
    {
        $this->actingAsUser();
        $keep = $this->author('Octavia', 'Butler');
        $typo = $this->author('Octavia', 'Buttler');
        $book = Book::factory()->create(['title' => 'Kindred', 'slug' => 'kindred']);
        $typo->books()->attach($book->book_id, ['author_ordinal' => 1]);

        $conflictId = $this->patchJson("/api/authors/{$typo->author_id}", [
            'first_name' => 'Octavia',
            'last_name' => 'Butler',
        ])->assertStatus(409)->json('conflict.author_id');

        $this->postJson("/api/authors/{$conflictId}/merge", ['source_ids' => [$typo->author_id]])->assertOk();

        $this->getJson('/api/book/kindred')->assertJsonPath('authors.0.author_id', $keep->author_id);
    }

    private function author(string $first, string $last): Author
    {
        return Author::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'slug' => AuthorService::slugFor($first, $last),
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $authors */
    private function bookPayload(Book $book, array $authors): array
    {
        return [
            'book' => ['title' => $book->title],
            'authors' => $authors,
            'genres' => [],
            'readInstances' => [],
            'versions' => [],
        ];
    }
}
