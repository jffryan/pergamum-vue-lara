<?php

namespace Tests\Feature\UserScoping;

use App\Models\Book;
use App\Models\BookList;
use App\Models\ListItem;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListItemsScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_item_on_other_users_list_returns_403(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = BookList::factory()->forUser($owner)->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $this->actingAsUser($intruder);

        $this->postJson("/api/lists/{$list->list_id}/items", [
            'version_id' => $version->version_id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('list_items', [
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
        ]);
    }

    public function test_destroy_item_from_other_users_list_returns_403(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = BookList::factory()->forUser($owner)->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $item = ListItem::factory()->create([
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
            'ordinal' => 0,
        ]);

        $this->actingAsUser($intruder);

        $this->deleteJson("/api/lists/{$list->list_id}/items/{$item->list_item_id}")
            ->assertForbidden();

        $this->assertDatabaseHas('list_items', [
            'list_item_id' => $item->list_item_id,
        ]);
    }

    public function test_owner_can_add_and_remove_items_on_own_list(): void
    {
        $owner = User::factory()->create();
        $list = BookList::factory()->forUser($owner)->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $this->actingAsUser($owner);

        $created = $this->postJson("/api/lists/{$list->list_id}/items", [
            'version_id' => $version->version_id,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('list_items', [
            'list_item_id' => $created['list_item_id'],
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
        ]);

        $this->deleteJson("/api/lists/{$list->list_id}/items/{$created['list_item_id']}")
            ->assertNoContent();

        $this->assertDatabaseMissing('list_items', [
            'list_item_id' => $created['list_item_id'],
        ]);
    }
}
