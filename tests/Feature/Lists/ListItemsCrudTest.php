<?php

namespace Tests\Feature\Lists;

use App\Models\Book;
use App\Models\BookList;
use App\Models\ListItem;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListItemsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_assigns_ordinal_zero_for_first_item(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $response = $this->postJson("/api/lists/{$list->list_id}/items", [
            'version_id' => $version->version_id,
        ]);

        $response->assertCreated()->assertJsonStructure([
            'list_item_id', 'list_id', 'version_id', 'ordinal',
            'version' => ['version_id', 'format', 'book' => ['book_id', 'authors']],
        ]);
        $this->assertSame(0, $response->json('ordinal'));
        $this->assertDatabaseHas('list_items', [
            'list_item_id' => $response->json('list_item_id'),
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
            'ordinal' => 0,
        ]);
    }

    public function test_store_appends_after_max_existing_ordinal(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        // Seed items with sparse ordinals so we exercise max+1, not just count.
        $existingBook = Book::factory()->create();
        $existingVersion = Version::factory()->for($existingBook, 'book')->create();
        ListItem::factory()->create([
            'list_id' => $list->list_id,
            'version_id' => $existingVersion->version_id,
            'ordinal' => 7,
        ]);

        $newBook = Book::factory()->create();
        $newVersion = Version::factory()->for($newBook, 'book')->create();

        $response = $this->postJson("/api/lists/{$list->list_id}/items", [
            'version_id' => $newVersion->version_id,
        ]);

        $response->assertCreated();
        $this->assertSame(8, $response->json('ordinal'));
    }

    public function test_store_validates_version_id(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();

        $this->postJson("/api/lists/{$list->list_id}/items", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('version_id');

        $this->postJson("/api/lists/{$list->list_id}/items", ['version_id' => 9999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('version_id');
    }

    public function test_destroy_returns_404_when_item_belongs_to_a_different_list(): void
    {
        // Both lists are owned by the SAME user on purpose: this test pins list-membership
        // routing, not authorization/scoping (covered in tests/Feature/UserScoping/ListItemsScopingTest.php).
        $user = $this->actingAsUser();
        $listA = BookList::factory()->forUser($user)->create();
        $listB = BookList::factory()->forUser($user)->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $itemOnB = ListItem::factory()->create([
            'list_id' => $listB->list_id,
            'version_id' => $version->version_id,
            'ordinal' => 0,
        ]);

        $this->deleteJson("/api/lists/{$listA->list_id}/items/{$itemOnB->list_item_id}")
            ->assertNotFound()
            ->assertJson(['message' => 'Item does not belong to this list.']);

        $this->assertDatabaseHas('list_items', ['list_item_id' => $itemOnB->list_item_id]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $list = BookList::factory()->create();

        $this->postJson("/api/lists/{$list->list_id}/items", ['version_id' => 1])
            ->assertUnauthorized();

        $this->deleteJson("/api/lists/{$list->list_id}/items/1")
            ->assertUnauthorized();
    }
}
