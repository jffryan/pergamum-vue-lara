<?php

namespace Tests\Feature\Export;

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The claim `GET /api/export` exists to make: a catalog exported, wiped and
 * re-imported is the same catalog. Anything that fails to survive that trip is
 * data a database reset would lose.
 */
class CatalogRoundtripTest extends TestCase
{
    use RefreshDatabase;

    private function paper(): Format
    {
        return Format::create([
            'name' => 'Physical',
            'slug' => 'physical',
            'expects_page_count' => true,
            'expects_audio_runtime' => false,
        ]);
    }

    private function audio(): Format
    {
        return Format::create([
            'name' => 'Graphic Novel',
            'slug' => 'graphic-novel',
            'expects_page_count' => true,
            'expects_audio_runtime' => false,
        ]);
    }

    private function audiobook(): Format
    {
        return Format::create([
            'name' => 'Audiobook',
            'slug' => 'audiobook',
            'expects_page_count' => false,
            'expects_audio_runtime' => true,
        ]);
    }

    /**
     * A snapshot of everything the CSV claims to carry, in a form that survives
     * primary keys being reassigned by the reimport.
     */
    private function snapshot(int $userId): array
    {
        $books = Book::with(['authors', 'genres', 'versions.format', 'versions.readInstances'])
            ->orderBy('slug')
            ->get()
            ->map(fn ($book) => [
                'title' => $book->title,
                'slug' => $book->slug,
                'authors' => $book->authors
                    ->sortBy(fn ($a) => $a->pivot->author_ordinal)
                    ->map(fn ($a) => $a->first_name.'|'.$a->last_name)
                    ->values()->all(),
                'genres' => $book->genres->pluck('name')->sort()->values()->all(),
                'versions' => $book->versions->sortBy('version_id')->map(fn ($v) => [
                    'format' => $v->format->name,
                    'page_count' => $v->page_count,
                    'audio_runtime' => $v->audio_runtime,
                    'nickname' => $v->nickname,
                    'is_discarded' => (bool) $v->is_discarded,
                    'discarded_at' => $v->discarded_at?->format('Y-m-d'),
                    'reads' => $v->readInstances
                        ->sortBy([['date_read', 'asc'], ['rating', 'asc']])
                        ->map(fn ($r) => [
                            'date_read' => $r->date_read?->format('Y-m-d'),
                            'rating' => $r->rating,
                        ])->values()->all(),
                ])->values()->all(),
            ])->values()->all();

        $lists = BookList::where('user_id', $userId)
            ->with('items.version.book')
            ->orderBy('slug')
            ->get()
            ->map(fn ($list) => [
                'name' => $list->name,
                'slug' => $list->slug,
                'items' => $list->items->sortBy('ordinal')->map(fn ($item) => [
                    'ordinal' => $item->ordinal,
                    'book' => $item->version->book->slug,
                    'format' => $item->version->format_id,
                ])->values()->all(),
            ])->values()->all();

        return ['books' => $books, 'lists' => $lists];
    }

    /**
     * Build a catalog that exercises every column: multi-author, multi-genre,
     * multi-version, a re-read, an unrated read, an unread copy, a discarded
     * copy with and without a date, an audiobook with no page count, and two
     * overlapping lists.
     */
    private function seedCatalog(User $user): void
    {
        $paper = $this->paper();
        $audiobook = $this->audiobook();
        $graphic = $this->audio();

        $dune = Book::create(['title' => 'Dune', 'slug' => 'dune']);
        $herbert = Author::create(['first_name' => 'Frank', 'last_name' => 'Herbert', 'slug' => 'frank-herbert']);
        $anderson = Author::create(['first_name' => 'Brian', 'last_name' => 'Anderson', 'slug' => 'brian-anderson']);
        $dune->authors()->attach($herbert->author_id, ['author_ordinal' => 1]);
        $dune->authors()->attach($anderson->author_id, ['author_ordinal' => 2]);

        $scifi = Genre::create(['name' => 'science fiction']);
        $classic = Genre::create(['name' => 'classic']);
        $dune->genres()->attach([$scifi->genre_id, $classic->genre_id]);

        $dunePaper = Version::create([
            'book_id' => $dune->book_id, 'format_id' => $paper->format_id,
            'page_count' => 604, 'nickname' => 'Ace paperback',
        ]);
        $duneAudio = Version::create([
            'book_id' => $dune->book_id, 'format_id' => $audiobook->format_id,
            'audio_runtime' => 1260,
        ]);

        // Two reads of the paperback, one of them unrated; the audiobook is
        // owned but unread.
        ReadInstance::create([
            'user_id' => $user->user_id, 'book_id' => $dune->book_id,
            'version_id' => $dunePaper->version_id, 'date_read' => '2023-04-01', 'rating' => 4.5,
        ]);
        ReadInstance::create([
            'user_id' => $user->user_id, 'book_id' => $dune->book_id,
            'version_id' => $dunePaper->version_id, 'date_read' => '2024-11-30', 'rating' => null,
        ]);

        // A multi-word format, to pin that the name lookup handles one.
        $watchmen = Book::create(['title' => 'Watchmen', 'slug' => 'watchmen']);
        $moore = Author::create(['first_name' => 'Alan', 'last_name' => 'Moore', 'slug' => 'alan-moore']);
        $watchmen->authors()->attach($moore->author_id, ['author_ordinal' => 1]);
        $watchmenCopy = Version::create([
            'book_id' => $watchmen->book_id, 'format_id' => $graphic->format_id,
            'page_count' => 416, 'is_discarded' => true, 'discarded_at' => '2021-06-15',
        ]);

        // Discarded, date unknown — the case the discard feature was built for.
        $gone = Book::create(['title' => 'A Book We Gave Away', 'slug' => 'a-book-we-gave-away']);
        $gone->authors()->attach($moore->author_id, ['author_ordinal' => 1]);
        Version::create([
            'book_id' => $gone->book_id, 'format_id' => $paper->format_id,
            'page_count' => 120, 'is_discarded' => true, 'discarded_at' => null,
        ]);

        $toRead = BookList::create(['name' => 'Want to Read', 'slug' => 'want-to-read', 'user_id' => $user->user_id]);
        $favourites = BookList::create(['name' => 'Favourites', 'slug' => 'favourites', 'user_id' => $user->user_id]);

        // The audiobook sits at a different position on each list — the case
        // row order alone cannot reproduce.
        ListItem::create(['list_id' => $toRead->list_id, 'version_id' => $duneAudio->version_id, 'ordinal' => 0]);
        ListItem::create(['list_id' => $toRead->list_id, 'version_id' => $watchmenCopy->version_id, 'ordinal' => 1]);
        ListItem::create(['list_id' => $favourites->list_id, 'version_id' => $dunePaper->version_id, 'ordinal' => 0]);
        ListItem::create(['list_id' => $favourites->list_id, 'version_id' => $duneAudio->version_id, 'ordinal' => 1]);
    }

    /**
     * Stands in for `migrate:fresh` — everything the CSV claims to carry goes,
     * formats and users stay (the reset runbook seeds the first and registers
     * the second before importing).
     *
     * DELETE rather than TRUNCATE: TRUNCATE is DDL and implicitly commits in
     * MySQL, which would end RefreshDatabase's wrapping transaction and leak
     * this test's catalog into the next one.
     */
    private function wipeEverythingButFormatsAndUsers(): void
    {
        foreach (['list_items', 'lists', 'read_instances', 'versions', 'book_genre', 'book_author', 'genres', 'authors', 'books'] as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_exported_catalog_reimports_to_the_same_catalog(): void
    {
        $user = $this->actingAsUser();
        $this->seedCatalog($user);

        $before = $this->snapshot($user->user_id);

        $csv = $this->get('/api/export')->assertOk()->streamedContent();

        $this->wipeEverythingButFormatsAndUsers();
        $this->assertSame(0, Book::count(), 'the wipe should leave nothing to find');

        $response = $this->postJson('/api/bulk-upload', [
            'csv_file' => UploadedFile::fake()->createWithContent('export.csv', $csv),
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('summary.failed'), json_encode($response->json('results')));

        $this->assertEquals($before, $this->snapshot($user->user_id));
    }

    public function test_export_carries_only_the_requesting_users_reads_and_lists(): void
    {
        $userA = $this->actingAsUser();
        $this->seedCatalog($userA);

        $userB = User::factory()->create();
        $this->actingAs($userB, 'sanctum');

        $csv = $this->get('/api/export')->assertOk()->streamedContent();

        // The shared catalog is still there…
        $this->assertStringContainsString('Dune', $csv);
        $this->assertStringContainsString('Watchmen', $csv);

        // …but none of user A's reading state is.
        $this->assertStringNotContainsString('2023-04-01', $csv);
        $this->assertStringNotContainsString('Want to Read', $csv);
        $this->assertStringNotContainsString('Favourites', $csv);
    }

    public function test_export_halves_the_stored_rating_back_to_the_display_scale(): void
    {
        $user = $this->actingAsUser();
        $paper = $this->paper();

        $book = Book::create(['title' => 'Rated', 'slug' => 'rated']);
        $author = Author::create(['first_name' => 'A', 'last_name' => 'B', 'slug' => 'a-b']);
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);
        $version = Version::create(['book_id' => $book->book_id, 'format_id' => $paper->format_id, 'page_count' => 10]);

        ReadInstance::create([
            'user_id' => $user->user_id, 'book_id' => $book->book_id,
            'version_id' => $version->version_id, 'date_read' => '2024-01-01', 'rating' => 4.5,
        ]);

        // Stored doubled by the mutator; the export undoes it, or a roundtrip
        // would double the rating on every pass.
        $this->assertSame(9, ReadInstance::first()->rating);

        $csv = $this->get('/api/export')->assertOk()->streamedContent();
        $this->assertStringContainsString(',4.5,', $csv);
    }

    public function test_export_header_is_the_shared_contract(): void
    {
        $this->actingAsUser();

        $csv = $this->get('/api/export')->assertOk()->streamedContent();
        $header = strtok($csv, "\n");

        $this->assertSame(
            'title,authors,format,page_count,audio_runtime,version_nickname,genres,date_read,rating,is_discarded,discarded_at,lists',
            trim($header)
        );
    }

    public function test_unauthenticated_export_is_rejected(): void
    {
        $this->getJson('/api/export')->assertUnauthorized();
    }

    public function test_a_version_on_no_list_and_with_no_reads_still_roundtrips(): void
    {
        $user = $this->actingAsUser();
        $paper = $this->paper();

        $book = Book::create(['title' => 'Unread', 'slug' => 'unread']);
        $author = Author::create(['first_name' => 'C', 'last_name' => 'D', 'slug' => 'c-d']);
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);
        Version::create(['book_id' => $book->book_id, 'format_id' => $paper->format_id, 'page_count' => 99]);

        $csv = $this->get('/api/export')->assertOk()->streamedContent();

        $this->wipeEverythingButFormatsAndUsers();
        $this->postJson('/api/bulk-upload', [
            'csv_file' => UploadedFile::fake()->createWithContent('export.csv', $csv),
        ])->assertOk();

        $this->assertSame(1, Version::count());
        $this->assertSame(99, Version::first()->page_count);
        $this->assertSame(
            0,
            ReadInstance::withoutGlobalScope(BelongsToCurrentUser::class)->count(),
            'a blank date_read must not invent a read'
        );
        $this->assertSame(0, BookList::where('user_id', $user->user_id)->count());
    }
}
