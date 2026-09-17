<?php

namespace Tests\Feature\Books;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Location;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /book/{slug}` — the order the detail payload arrives in.
 *
 * The book page reads positionally, the same way the library row does:
 * `authors[0]` is the primary author, reads run newest-first, copies run
 * oldest-first. None of that was a promise before — the eager loads were
 * unordered, so "primary author" was whichever pivot row came back first and
 * the read history was in insertion order. These are the guarantees
 * `resources/js/utils/bookDetail.js` is written against.
 */
class BookDetailPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function author(string $first, string $last): Author
    {
        return Author::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    public function test_authors_arrive_in_ordinal_order(): void
    {
        $this->actingAsUser();

        $book = Book::factory()->create(['title' => 'Dune', 'slug' => 'dune']);
        $second = $this->author('Brian', 'Herbert');
        $first = $this->author('Frank', 'Herbert');

        // Attached out of order on purpose: insertion order and ordinal order
        // disagree, which is the case an unordered eager load got wrong.
        $book->authors()->attach($second->author_id, ['author_ordinal' => 2]);
        $book->authors()->attach($first->author_id, ['author_ordinal' => 1]);

        $response = $this->getJson('/api/book/dune');

        $response->assertOk();
        $this->assertSame(
            ['Frank', 'Brian'],
            collect($response->json('authors'))->pluck('first_name')->all()
        );
    }

    public function test_read_instances_arrive_newest_first(): void
    {
        $user = $this->actingAsUser();

        $book = Book::factory()->create(['slug' => 'dune']);
        $version = Version::factory()->for($book, 'book')->create();

        foreach (['2014-06-03', '2022-03-12', '2008-06-01'] as $date) {
            ReadInstance::factory()->create([
                'user_id' => $user->user_id,
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => $date,
            ]);
        }

        $response = $this->getJson('/api/book/dune');

        $response->assertOk();
        $this->assertSame(
            ['2022-03-12', '2014-06-03', '2008-06-01'],
            collect($response->json('readInstances'))->pluck('date_read')->all()
        );
    }

    public function test_an_undated_read_sorts_last_rather_than_first(): void
    {
        // "Read, date unknown" is a real state — much of the historical
        // library predates the tracking — and it belongs at the bottom of the
        // history, not above the most recent read.
        $user = $this->actingAsUser();

        $book = Book::factory()->create(['slug' => 'dune']);
        $version = Version::factory()->for($book, 'book')->create();

        ReadInstance::factory()->create([
            'user_id' => $user->user_id,
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => null,
        ]);
        ReadInstance::factory()->create([
            'user_id' => $user->user_id,
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2022-03-12',
        ]);

        $response = $this->getJson('/api/book/dune');

        $response->assertOk();
        $this->assertSame(
            ['2022-03-12', null],
            collect($response->json('readInstances'))->pluck('date_read')->all()
        );
    }

    public function test_only_the_current_users_reads_are_returned(): void
    {
        $user = $this->actingAsUser();
        $other = User::factory()->create();

        $book = Book::factory()->create(['slug' => 'dune']);
        $version = Version::factory()->for($book, 'book')->create();

        foreach ([$user->user_id, $other->user_id] as $reader) {
            ReadInstance::factory()->create([
                'user_id' => $reader,
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => '2022-03-12',
            ]);
        }

        $response = $this->getJson('/api/book/dune');

        $response->assertOk();
        $this->assertCount(1, $response->json('readInstances'));
        $this->assertSame($user->user_id, $response->json('readInstances.0.user_id'));
    }

    public function test_copies_carry_their_format_and_location(): void
    {
        // The page renders a copy's length in the unit its format declares and
        // links its shelf; both relations have to be in the payload.
        $this->actingAsUser();

        $book = Book::factory()->create(['slug' => 'dune']);
        $shelf = Location::factory()->create(['code' => 'O1S5', 'slug' => 'o1s5']);
        $audio = Format::factory()->create([
            'name' => 'Audiobook',
            'expects_page_count' => false,
            'expects_audio_runtime' => true,
        ]);

        Version::factory()->for($book, 'book')->create([
            'format_id' => $audio->format_id,
            'audio_runtime' => 1268,
            'page_count' => null,
            'location_id' => $shelf->location_id,
        ]);

        $response = $this->getJson('/api/book/dune');

        $response->assertOk();
        $response->assertJsonPath('versions.0.format.name', 'Audiobook');
        $response->assertJsonPath('versions.0.format.expects_audio_runtime', true);
        $response->assertJsonPath('versions.0.location.code', 'O1S5');
        $response->assertJsonPath('versions.0.location.slug', 'o1s5');
    }

    public function test_related_books_are_ordered_and_do_not_repeat_a_shared_book(): void
    {
        // An unordered `limit()` returned a different set on consecutive
        // requests, so the block flickered on reload; a book sharing two
        // authors must still appear once.
        $this->actingAsUser();

        $herbert = $this->author('Frank', 'Herbert');
        $brian = $this->author('Brian', 'Herbert');

        $book = Book::factory()->create(['title' => 'Dune', 'slug' => 'dune']);
        $book->authors()->attach($herbert->author_id, ['author_ordinal' => 1]);
        $book->authors()->attach($brian->author_id, ['author_ordinal' => 2]);

        foreach (['Heretics of Dune', 'Children of Dune', 'Dune Messiah'] as $title) {
            $other = Book::factory()->create(['title' => $title]);
            $other->authors()->attach($herbert->author_id, ['author_ordinal' => 1]);
            $other->authors()->attach($brian->author_id, ['author_ordinal' => 2]);
        }

        $titles = collect($this->getJson('/api/book/dune')->json('authorRelatedBooks'))
            ->pluck('book.title')
            ->all();

        $this->assertSame(
            ['Children of Dune', 'Dune Messiah', 'Heretics of Dune'],
            $titles
        );
    }

    public function test_the_book_itself_is_not_in_its_related_books(): void
    {
        $this->actingAsUser();

        $herbert = $this->author('Frank', 'Herbert');
        $book = Book::factory()->create(['title' => 'Dune', 'slug' => 'dune']);
        $book->authors()->attach($herbert->author_id, ['author_ordinal' => 1]);

        $this->assertSame([], $this->getJson('/api/book/dune')->json('authorRelatedBooks'));
    }
}
