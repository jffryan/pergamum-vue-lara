<?php

namespace Tests\Feature\UserScoping;

use App\Models\Book;
use App\Models\BookList;
use App\Models\ListItem;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListsScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_authenticated_users_lists(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $listA = BookList::factory()->forUser($userA)->create();
        $listB1 = BookList::factory()->forUser($userB)->create();
        $listB2 = BookList::factory()->forUser($userB)->create();

        $this->actingAsUser($userB);

        $response = $this->getJson('/api/lists');

        $response->assertOk();
        $ids = collect($response->json())->pluck('list_id')->all();
        sort($ids);
        $expected = [$listB1->list_id, $listB2->list_id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($listA->list_id, $ids);
    }

    public function test_show_other_users_list_returns_403(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = BookList::factory()->forUser($owner)->create();

        $this->actingAsUser($intruder);

        $this->getJson("/api/lists/{$list->list_id}")->assertForbidden();
    }

    public function test_update_other_users_list_returns_403(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = BookList::factory()->forUser($owner)->create(['name' => 'Original']);

        $this->actingAsUser($intruder);

        $this->putJson("/api/lists/{$list->list_id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('lists', [
            'list_id' => $list->list_id,
            'name' => 'Original',
        ]);
    }

    public function test_destroy_other_users_list_returns_403(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = BookList::factory()->forUser($owner)->create();

        $this->actingAsUser($intruder);

        $this->deleteJson("/api/lists/{$list->list_id}")->assertForbidden();

        $this->assertDatabaseHas('lists', ['list_id' => $list->list_id]);
    }

    public function test_reorder_other_users_list_returns_403(): void
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

        $this->patchJson("/api/lists/{$list->list_id}/reorder", [
            'items' => [$item->list_item_id],
        ])->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $owner = User::factory()->create();
        $list = BookList::factory()->forUser($owner)->create();

        $this->getJson("/api/lists/{$list->list_id}")->assertUnauthorized();
    }
}
