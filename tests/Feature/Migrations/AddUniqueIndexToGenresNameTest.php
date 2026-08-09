<?php

namespace Tests\Feature\Migrations;

use App\Models\Genre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `RefreshDatabase` has already run this migration by the time a test starts,
 * so each case drops the index first and rebuilds the state it wants.
 */
class AddUniqueIndexToGenresNameTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_09_000000_add_unique_index_to_genres_name.php');
    }

    private function indexExists(): bool
    {
        return DB::select("SHOW INDEX FROM genres WHERE Key_name = 'genres_name_unique'") !== [];
    }

    private function dropIndex(): void
    {
        if ($this->indexExists()) {
            $this->migration()->down();
        }
    }

    /**
     * MySQL implicitly commits on DDL, which ends the transaction
     * `RefreshDatabase` opened. So neither an index this test dropped nor the
     * rows it wrote afterwards can be rolled back — both have to be undone by
     * hand, or the next test in the process inherits a table with no index.
     */
    protected function tearDown(): void
    {
        DB::table('book_genre')->delete();
        DB::table('genres')->delete();

        if (! $this->indexExists()) {
            $this->migration()->up();
        }

        parent::tearDown();
    }

    public function test_up_adds_an_index_that_rejects_a_duplicate_name(): void
    {
        Genre::factory()->create(['name' => 'Fantasy']);

        $this->expectException(QueryException::class);

        // Straight to the query builder: `GenreService` guards this path, and
        // the point of the index is to catch the writes that don't go through it.
        DB::table('genres')->insert(['name' => 'Fantasy', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_index_is_case_insensitive(): void
    {
        Genre::factory()->create(['name' => 'Fantasy']);

        $this->expectException(QueryException::class);

        // Inherited from `utf8mb4_unicode_ci`, not from anything in PHP —
        // a collation change would silently turn this off.
        DB::table('genres')->insert(['name' => 'fantasy', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_up_refuses_to_run_while_duplicates_exist_and_names_them(): void
    {
        $this->dropIndex();

        $now = now();
        DB::table('genres')->insert([
            ['name' => 'Fantasy', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'fantasy', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Essays', 'created_at' => $now, 'updated_at' => $now],
        ]);

        try {
            $this->migration()->up();
            $this->fail('the migration should refuse to build the index over duplicate names');
        } catch (\RuntimeException $e) {
            // The operator needs to know which names to merge, not just that
            // one of them collided.
            $this->assertStringContainsString('Fantasy', $e->getMessage());
            $this->assertStringContainsString('/admin/genres', $e->getMessage());
            $this->assertStringNotContainsString('Essays', $e->getMessage(), 'a name held by one row is not an offender');
        }

        $this->assertSame(3, DB::table('genres')->count(), 'a refused migration must not delete anything');
    }

    public function test_up_succeeds_once_the_duplicates_are_gone(): void
    {
        $this->dropIndex();

        $now = now();
        DB::table('genres')->insert([
            ['name' => 'Fantasy', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Essays', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->migration()->up();

        $this->expectException(QueryException::class);
        DB::table('genres')->insert(['name' => 'Essays', 'created_at' => $now, 'updated_at' => $now]);
    }
}
