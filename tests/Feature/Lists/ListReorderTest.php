<?php

namespace Tests\Feature\Lists;

use App\Models\Book;
use App\Models\BookList;
use App\Models\ListItem;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListReorderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper: build a list with $count items and return [list, [items in original order]].
     *
     * Original ordinals are 0..n-1.
     */
    private function listWithItems(int $count): array
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $book = Book::factory()->create();
            $version = Version::factory()->for($book, 'book')->create();
            $items[] = ListItem::factory()->create([
                'list_id' => $list->list_id,
                'version_id' => $version->version_id,
                'ordinal' => $i,
            ]);
        }

        return [$list, $items];
    }

    public function test_reorder_persists_new_ordinals_and_returns_items_in_order(): void
    {
        [$list, $items] = $this->listWithItems(3);
        [$first, $second, $third] = $items;

        $newOrder = [$third->list_item_id, $first->list_item_id, $second->list_item_id];

        $response = $this->patchJson("/api/lists/{$list->list_id}/reorder", [
            'items' => $newOrder,
        ]);

        $response->assertOk()->assertJsonStructure([
            'list_id',
            'items' => [['list_item_id', 'ordinal', 'version' => ['version_id', 'format', 'book' => ['authors']]]],
        ]);

        $this->assertDatabaseHas('list_items', ['list_item_id' => $third->list_item_id, 'ordinal' => 0]);
        $this->assertDatabaseHas('list_items', ['list_item_id' => $first->list_item_id, 'ordinal' => 1]);
        $this->assertDatabaseHas('list_items', ['list_item_id' => $second->list_item_id, 'ordinal' => 2]);

        $returnedIds = collect($response->json('items'))->pluck('list_item_id')->all();
        $this->assertSame($newOrder, $returnedIds, 'items relation orders by ordinal so the new sequence should be reflected');
    }

    public function test_reorder_idempotent_when_items_supplied_in_existing_order(): void
    {
        [$list, $items] = $this->listWithItems(2);

        $sameOrder = [$items[0]->list_item_id, $items[1]->list_item_id];

        $this->patchJson("/api/lists/{$list->list_id}/reorder", ['items' => $sameOrder])
            ->assertOk();

        $this->assertDatabaseHas('list_items', ['list_item_id' => $items[0]->list_item_id, 'ordinal' => 0]);
        $this->assertDatabaseHas('list_items', ['list_item_id' => $items[1]->list_item_id, 'ordinal' => 1]);
    }

    public function test_reorder_rejects_payload_missing_items_from_list(): void
    {
        [$list, $items] = $this->listWithItems(3);

        // Drop one item — the controller requires the full set.
        $partial = [$items[0]->list_item_id, $items[1]->list_item_id];

        $response = $this->patchJson("/api/lists/{$list->list_id}/reorder", ['items' => $partial]);

        $response->assertStatus(422)->assertJson(['message' => 'Invalid item IDs for this list.']);

        // No reordering should have occurred.
        $this->assertDatabaseHas('list_items', ['list_item_id' => $items[0]->list_item_id, 'ordinal' => 0]);
        $this->assertDatabaseHas('list_items', ['list_item_id' => $items[1]->list_item_id, 'ordinal' => 1]);
        $this->assertDatabaseHas('list_items', ['list_item_id' => $items[2]->list_item_id, 'ordinal' => 2]);
    }

    public function test_reorder_rejects_item_id_belonging_to_another_list(): void
    {
        [$listA, $itemsA] = $this->listWithItems(2);

        // Same authenticated user owns a second list.
        $user = auth()->user();
        $listB = BookList::factory()->forUser($user)->create();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $foreignItem = ListItem::factory()->create([
            'list_id' => $listB->list_id,
            'version_id' => $version->version_id,
            'ordinal' => 0,
        ]);

        $payload = [$itemsA[0]->list_item_id, $foreignItem->list_item_id];

        $this->patchJson("/api/lists/{$listA->list_id}/reorder", ['items' => $payload])
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid item IDs for this list.']);

        // listA items untouched, foreign item not relocated.
        $this->assertDatabaseHas('list_items', ['list_item_id' => $itemsA[0]->list_item_id, 'list_id' => $listA->list_id, 'ordinal' => 0]);
        $this->assertDatabaseHas('list_items', ['list_item_id' => $foreignItem->list_item_id, 'list_id' => $listB->list_id, 'ordinal' => 0]);
    }

    public function test_reorder_validates_payload_shape(): void
    {
        [$list] = $this->listWithItems(1);

        $this->patchJson("/api/lists/{$list->list_id}/reorder", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->patchJson("/api/lists/{$list->list_id}/reorder", ['items' => ['not-an-int']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0');
    }

    public function test_reorder_returns_404_for_unknown_list(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/lists/9999999/reorder', ['items' => []])->assertNotFound();
    }

    /**
     * The membership check moved into `ReorderListRequest`, so the response
     * now says *which* ids were wrong instead of only that some were. The
     * leading message is unchanged — callers key on it.
     */
    public function test_reorder_names_the_offending_ids(): void
    {
        [$list, $items] = $this->listWithItems(3);

        $response = $this->patchJson("/api/lists/{$list->list_id}/reorder", [
            'items' => [$items[0]->list_item_id, 999_999],
        ]);

        $response->assertStatus(422)->assertJson(['message' => 'Invalid item IDs for this list.']);

        $errors = implode(' ', $response->json('errors.items'));
        $this->assertStringContainsString('999999', $errors, 'the id that is not ours should be named');
        $this->assertStringContainsString((string) $items[1]->list_item_id, $errors, 'the ids left out should be named');
        $this->assertStringContainsString((string) $items[2]->list_item_id, $errors);
    }

    /**
     * A repeated id used to pass the count-based membership check, write one
     * item's ordinal twice and leave another's untouched — and with the
     * (list_id, ordinal) unique index dropped, nothing rejected the collision.
     */
    public function test_reorder_rejects_a_repeated_item_id(): void
    {
        [$list, $items] = $this->listWithItems(3);

        $this->patchJson("/api/lists/{$list->list_id}/reorder", [
            'items' => [
                $items[0]->list_item_id,
                $items[0]->list_item_id,
                $items[1]->list_item_id,
                $items[2]->list_item_id,
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0');

        foreach ($items as $i => $item) {
            $this->assertDatabaseHas('list_items', [
                'list_item_id' => $item->list_item_id,
                'ordinal' => $i,
            ]);
        }
    }

    /**
     * Ownership is checked before membership. Otherwise reordering someone
     * else's list would answer "those ids aren't in it" — which is itself an
     * answer about a list you can't see.
     */
    public function test_reorder_of_a_foreign_list_is_a_403_not_a_422(): void
    {
        [$list] = $this->listWithItems(2);

        $intruder = User::factory()->create();
        $this->actingAs($intruder, 'sanctum');

        $this->patchJson("/api/lists/{$list->list_id}/reorder", ['items' => [1, 2]])
            ->assertForbidden();
    }
}
