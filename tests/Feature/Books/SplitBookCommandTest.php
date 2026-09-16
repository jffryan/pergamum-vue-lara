<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookList;
use App\Models\Format;
use App\Models\Genre;
use App\Models\ListItem;
use App\Models\ReadInstance;
use App\Models\Scopes\BelongsToCurrentUser;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `book:split` — the repair for two books merged by a title-only match.
 */
class SplitBookCommandTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    private Author $plath;

    private Author $rodo;

    private Author $peden;

    private Version $plathCopy;

    private Version $rodoCopy;

    private Genre $poetry;

    private Genre $essay;

    protected function setUp(): void
    {
        parent::setUp();

        $format = Format::factory()->create(['name' => 'Physical']);
        $this->book = Book::factory()->create(['title' => 'Ariel', 'slug' => 'ariel']);

        $this->plath = Author::factory()->create(['first_name' => 'Sylvia', 'last_name' => 'Plath', 'slug' => 'sylvia-plath']);
        $this->rodo = Author::factory()->create(['first_name' => 'José Enrique', 'last_name' => 'Rodó', 'slug' => 'jose-enrique-rodo']);
        $this->peden = Author::factory()->create(['first_name' => 'Margaret Sayers', 'last_name' => 'Peden', 'slug' => 'margaret-sayers-peden']);
        $this->book->authors()->attach([
            $this->plath->author_id => ['author_ordinal' => 1],
            $this->rodo->author_id => ['author_ordinal' => 2],
            $this->peden->author_id => ['author_ordinal' => 3],
        ]);

        $this->plathCopy = Version::factory()->for($this->book, 'book')->create(['format_id' => $format->format_id, 'page_count' => 85]);
        $this->rodoCopy = Version::factory()->for($this->book, 'book')->create(['format_id' => $format->format_id, 'page_count' => 156]);

        $this->poetry = Genre::create(['name' => 'poetry']);
        $this->essay = Genre::create(['name' => 'essay']);
        $this->book->genres()->attach([$this->poetry->genre_id, $this->essay->genre_id]);
    }

    private function splitArgs(array $extra = []): array
    {
        return $extra + [
            'book' => 'ariel',
            '--copy' => [$this->rodoCopy->version_id],
            '--author' => ['jose-enrique-rodo', (string) $this->peden->author_id],
            '--genre' => ['Essay'],
        ];
    }

    public function test_moves_the_named_copies_authors_and_genres_onto_a_new_book(): void
    {
        $this->actingAsUser();

        $this->artisan('book:split', $this->splitArgs())
            ->expectsOutputToContain('slug: ariel-rodo')
            ->assertSuccessful();

        $created = Book::where('slug', 'ariel-rodo')->firstOrFail();
        $this->assertSame('Ariel', $created->title);

        $this->assertSame($created->book_id, $this->rodoCopy->fresh()->book_id);
        $this->assertSame($this->book->book_id, $this->plathCopy->fresh()->book_id);

        $this->assertSame(['sylvia-plath'], $this->book->authors()->pluck('slug')->all());
        $this->assertSame(
            [['jose-enrique-rodo', 1], ['margaret-sayers-peden', 2]],
            $created->authors()->orderBy('author_ordinal')->get()->map(fn ($a) => [$a->slug, $a->pivot->author_ordinal])->all(),
            'ordinals restart at 1 and keep their order'
        );

        $this->assertSame(['poetry'], $this->book->genres()->pluck('name')->all());
        $this->assertSame(['essay'], $created->genres()->pluck('name')->all());
    }

    public function test_read_history_and_list_items_follow_their_copy_for_every_account(): void
    {
        $user = $this->actingAsUser();
        $other = User::factory()->create();

        $mine = ReadInstance::create(['user_id' => $user->user_id, 'book_id' => $this->book->book_id, 'version_id' => $this->rodoCopy->version_id, 'date_read' => '2026-01-01']);
        $theirs = ReadInstance::withoutGlobalScope(BelongsToCurrentUser::class)->create(['user_id' => $other->user_id, 'book_id' => $this->book->book_id, 'version_id' => $this->rodoCopy->version_id, 'date_read' => '2026-02-02']);
        $stays = ReadInstance::create(['user_id' => $user->user_id, 'book_id' => $this->book->book_id, 'version_id' => $this->plathCopy->version_id, 'date_read' => '2026-03-03']);

        $list = BookList::create(['user_id' => $user->user_id, 'name' => 'Shelf', 'slug' => 'shelf']);
        $item = ListItem::create(['list_id' => $list->list_id, 'version_id' => $this->rodoCopy->version_id, 'ordinal' => 1]);

        $this->artisan('book:split', $this->splitArgs())->assertSuccessful();

        $created = Book::where('slug', 'ariel-rodo')->firstOrFail();
        // A fresh builder per lookup: `find` adds a where to the builder it's
        // called on, so reusing one stacks the keys.
        $unscoped = fn ($id) => ReadInstance::withoutGlobalScope(BelongsToCurrentUser::class)->find($id);

        $this->assertSame($created->book_id, $unscoped($mine->read_instance_id)->book_id);
        $this->assertSame($created->book_id, $unscoped($theirs->read_instance_id)->book_id);
        $this->assertSame($this->book->book_id, $unscoped($stays->read_instance_id)->book_id);
        $this->assertSame($this->rodoCopy->version_id, $item->fresh()->version_id);
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $this->actingAsUser();

        $this->artisan('book:split', $this->splitArgs(['--dry-run' => true]))
            ->expectsOutputToContain('Would create')
            ->assertSuccessful();

        $this->assertSame(1, Book::count());
        $this->assertSame($this->book->book_id, $this->rodoCopy->fresh()->book_id);
        $this->assertCount(3, $this->book->authors()->get());
    }

    public function test_an_unknown_copy_or_author_fails_before_anything_moves(): void
    {
        $this->actingAsUser();

        $this->artisan('book:split', $this->splitArgs(['--copy' => [999999]]))->assertFailed();
        $this->artisan('book:split', $this->splitArgs(['--author' => ['nobody']]))->assertFailed();
        $this->artisan('book:split', $this->splitArgs(['--genre' => ['jazz']]))->assertFailed();

        $this->assertSame(1, Book::count());
    }

    public function test_refuses_to_empty_the_original(): void
    {
        $this->actingAsUser();

        $this->artisan('book:split', $this->splitArgs([
            '--copy' => [$this->rodoCopy->version_id, $this->plathCopy->version_id],
        ]))->assertFailed();

        $this->artisan('book:split', $this->splitArgs([
            '--author' => ['sylvia-plath', 'jose-enrique-rodo', 'margaret-sayers-peden'],
        ]))->assertFailed();

        $this->artisan('book:split', $this->splitArgs(['--copy' => []]))->assertFailed();

        $this->assertSame(1, Book::count());
    }

    public function test_title_option_names_the_new_book(): void
    {
        $this->actingAsUser();

        $this->artisan('book:split', $this->splitArgs(['--title' => 'Ariel: Essays']))->assertSuccessful();

        $this->assertDatabaseHas('books', ['title' => 'Ariel: Essays', 'slug' => 'ariel-essays']);
    }
}
