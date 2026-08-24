<?php

namespace Tests\Feature\Migrations;

use App\Models\BookList;
use App\Models\ListItem;
use App\Models\Location;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The shelf-list backfill. Data-only (no DDL), so unlike the index migration
 * tests it runs cleanly inside `RefreshDatabase`'s transaction — each case
 * seeds lists, replays `up()`, and asserts on what it built.
 */
class BackfillLocationsFromShelfListsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_22_000002_backfill_locations_from_shelf_lists.php');
    }

    private function shelfList(User $user, string $name): BookList
    {
        return BookList::factory()->create([
            'user_id' => $user->user_id,
            'name' => $name,
            'slug' => strtolower($name),
        ]);
    }

    private function fileOnList(BookList $list, Version $version, int $ordinal): void
    {
        ListItem::factory()->create([
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
            'ordinal' => $ordinal,
        ]);
    }

    public function test_shelf_lists_become_a_room_bookcase_shelf_tree_and_versions_are_placed(): void
    {
        $user = User::factory()->create();
        $shelf = $this->shelfList($user, 'O1S2');
        $version = Version::factory()->create();
        $this->fileOnList($shelf, $version, 5);

        $this->migration()->up();

        $room = Location::where('slug', 'o')->first();
        $bookcase = Location::where('slug', 'o1')->first();
        $shelfRow = Location::where('slug', 'o1s2')->first();

        $this->assertSame('Office', $room->name);
        $this->assertSame('room', $room->kind);
        $this->assertSame($room->location_id, $bookcase->parent_id);
        $this->assertSame($bookcase->location_id, $shelfRow->parent_id);
        $this->assertSame(2, $shelfRow->ordinal);

        $this->assertDatabaseHas('versions', [
            'version_id' => $version->version_id,
            'location_id' => $shelfRow->location_id,
            'shelf_ordinal' => 5,
        ]);
    }

    public function test_each_level_is_created_once_across_sibling_shelves(): void
    {
        $user = User::factory()->create();
        $this->fileOnList($this->shelfList($user, 'O1S1'), Version::factory()->create(), 1);
        $this->fileOnList($this->shelfList($user, 'O1S2'), Version::factory()->create(), 1);
        $this->fileOnList($this->shelfList($user, 'H1S1'), Version::factory()->create(), 1);

        $this->migration()->up();

        $this->assertSame(2, Location::kind('room')->count());
        $this->assertSame(2, Location::kind('bookcase')->count());
        $this->assertSame(3, Location::kind('shelf')->count());
    }

    public function test_non_shelf_lists_are_ignored_and_no_list_is_dropped(): void
    {
        $user = User::factory()->create();
        $tbr = BookList::factory()->create(['user_id' => $user->user_id, 'name' => 'TBR', 'slug' => 'tbr']);
        $shelf = $this->shelfList($user, 'B1S3');
        $this->fileOnList($shelf, Version::factory()->create(), 1);

        $this->migration()->up();

        $this->assertSame(3, Location::count());
        $this->assertDatabaseHas('lists', ['list_id' => $tbr->list_id]);
        $this->assertDatabaseHas('lists', ['list_id' => $shelf->list_id]);
    }

    public function test_a_version_on_two_shelf_lists_refuses_and_names_the_offender(): void
    {
        $user = User::factory()->create();
        $version = Version::factory()->create();
        $this->fileOnList($this->shelfList($user, 'O1S1'), $version, 1);
        $this->fileOnList($this->shelfList($user, 'O1S2'), $version, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("version {$version->version_id}");

        $this->migration()->up();
    }

    public function test_an_empty_database_is_a_no_op(): void
    {
        $this->migration()->up();

        $this->assertSame(0, Location::count());
    }
}
