<?php

namespace Tests\Feature\Migrations;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookList;
use App\Models\Format;
use App\Support\Slugger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NormalizeBookAndAuthorSlugsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_02_000001_normalize_book_and_author_slugs.php');
    }

    /**
     * A title long enough that Slugger truncates it at a hyphen boundary.
     */
    private function longTitle(string $tail = 'End'): string
    {
        return str_repeat('Word ', 20).$tail;
    }

    public function test_long_slug_book_is_rewritten_and_a_correct_row_is_left_untouched(): void
    {
        $long = $this->longTitle();
        $stale = Book::create(['title' => $long, 'slug' => Str::slug($long)]);
        $fine = Book::create(['title' => 'Short Title', 'slug' => 'short-title']);
        $fineUpdatedAt = $fine->updated_at;

        $this->migration()->up();

        $this->assertSame(Slugger::for($long), $stale->fresh()->slug);
        $this->assertSame('short-title', $fine->fresh()->slug);
        $this->assertEquals($fineUpdatedAt, $fine->fresh()->updated_at);
    }

    public function test_two_titles_truncating_to_the_same_slug_get_suffixed(): void
    {
        $alpha = $this->longTitle('Alpha');
        $beta = $this->longTitle('Beta');

        // Precondition: these are distinct titles whose slugs truncate to one value.
        $this->assertNotSame($alpha, $beta);
        $this->assertSame(Slugger::for($alpha), Slugger::for($beta));

        $first = Book::create(['title' => $alpha, 'slug' => Str::slug($alpha)]);
        $second = Book::create(['title' => $beta, 'slug' => Str::slug($beta)]);

        $this->migration()->up();

        $base = Slugger::for($alpha);
        $this->assertSame($base, $first->fresh()->slug);
        $this->assertSame($base.'-2', $second->fresh()->slug);
    }

    public function test_truncated_candidate_suffixes_when_it_collides_with_an_existing_short_slug_row(): void
    {
        $long = $this->longTitle();
        $base = Slugger::for($long);

        // A short title that already derives to exactly the truncated slug, created first
        // so it holds the base by primary-key order.
        $shortTitle = str_replace('-', ' ', $base);
        $short = Book::create(['title' => $shortTitle, 'slug' => $base]);
        $stale = Book::create(['title' => $long, 'slug' => Str::slug($long)]);

        $this->migration()->up();

        $this->assertSame($base, $short->fresh()->slug);
        $this->assertSame($base.'-2', $stale->fresh()->slug);
    }

    public function test_rewrite_does_not_throw_when_a_final_slug_is_still_held_by_a_later_row(): void
    {
        $long = $this->longTitle();
        $base = Slugger::for($long);

        // Reverse of the test above: the long row has the lower primary key, so it claims
        // the base slug while a later row is still holding it. Writing in place would hit
        // the unique index; the migration parks changing rows on a temporary slug first.
        $stale = Book::create(['title' => $long, 'slug' => Str::slug($long)]);
        $short = Book::create(['title' => str_replace('-', ' ', $base), 'slug' => $base]);

        $this->migration()->up();

        $this->assertSame($base, $stale->fresh()->slug);
        $this->assertSame($base.'-2', $short->fresh()->slug);
    }

    public function test_running_the_migration_twice_is_a_no_op(): void
    {
        $long = $this->longTitle();
        $stale = Book::create(['title' => $long, 'slug' => Str::slug($long)]);
        Author::create([
            'first_name' => 'First',
            'last_name' => str_repeat('Longname ', 8).'End',
            'slug' => 'stale-author-slug',
        ]);

        $this->migration()->up();

        $booksAfterFirst = Book::orderBy('book_id')->pluck('slug', 'book_id')->all();
        $authorsAfterFirst = Author::orderBy('author_id')->pluck('slug', 'author_id')->all();

        $this->migration()->up();

        $this->assertSame($booksAfterFirst, Book::orderBy('book_id')->pluck('slug', 'book_id')->all());
        $this->assertSame($authorsAfterFirst, Author::orderBy('author_id')->pluck('slug', 'author_id')->all());
        $this->assertSame(Slugger::for($long), $stale->fresh()->slug);
    }

    public function test_authors_are_normalized_the_same_way_as_books(): void
    {
        $last = str_repeat('Longname ', 8).'End';
        $stale = Author::create([
            'first_name' => 'First',
            'last_name' => $last,
            'slug' => Str::slug('First '.$last),
        ]);
        $fine = Author::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'slug' => 'jane-doe',
        ]);

        $this->migration()->up();

        $this->assertSame(Slugger::for('First '.$last), $stale->fresh()->slug);
        $this->assertSame('jane-doe', $fine->fresh()->slug);
    }

    public function test_list_and_format_slugs_are_untouched(): void
    {
        $user = $this->actingAsUser();

        $longName = str_repeat('Word ', 20).'End';
        $list = BookList::create([
            'name' => $longName,
            'slug' => Str::slug($longName),
            'user_id' => $user->user_id,
        ]);
        $format = Format::factory()->create([
            'name' => $longName,
            'slug' => Str::slug($longName),
        ]);

        $this->migration()->up();

        $this->assertSame(Str::slug($longName), $list->fresh()->slug);
        $this->assertSame(Str::slug($longName), $format->fresh()->slug);
    }
}
