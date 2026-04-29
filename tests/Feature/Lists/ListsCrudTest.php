<?php

namespace Tests\Feature\Lists;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookList;
use App\Models\Format;
use App\Models\Genre;
use App\Models\ListItem;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_users_lists_with_items_subset(): void
    {
        $user = $this->actingAsUser();

        $list = BookList::factory()->forUser($user)->create();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $item = ListItem::factory()->create([
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
            'ordinal' => 0,
        ]);

        $response = $this->getJson('/api/lists');

        $response->assertOk()->assertJsonStructure([
            ['list_id', 'name', 'slug', 'user_id', 'items' => [['list_item_id', 'list_id', 'version_id']]],
        ]);
        $this->assertSame($list->list_id, $response->json('0.list_id'));
        $this->assertSame($item->list_item_id, $response->json('0.items.0.list_item_id'));
    }

    public function test_show_loads_deep_relations_and_scopes_read_instances_to_user(): void
    {
        $owner = $this->actingAsUser();
        $otherUser = \App\Models\User::factory()->create();

        $list = BookList::factory()->forUser($owner)->create();

        $book = Book::factory()->create();
        $author = Author::factory()->create();
        $book->authors()->attach($author->author_id, ['author_ordinal' => 1]);
        $genre = Genre::factory()->create();
        $book->genres()->attach($genre->genre_id);
        $format = Format::factory()->create();
        $version = Version::factory()->for($book, 'book')->create(['format_id' => $format->format_id]);

        ListItem::factory()->create([
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
            'ordinal' => 0,
        ]);

        ReadInstance::factory()->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'user_id' => $owner->user_id,
            'rating' => 5,
        ]);
        ReadInstance::factory()->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'user_id' => $otherUser->user_id,
            'rating' => 1,
        ]);

        $response = $this->getJson("/api/lists/{$list->list_id}");

        $response->assertOk()->assertJsonStructure([
            'list_id', 'name',
            'items' => [[
                'list_item_id',
                'version' => ['version_id', 'format', 'book' => ['book_id', 'authors', 'genres', 'read_instances']],
            ]],
        ]);

        $reads = $response->json('items.0.version.book.read_instances');
        $this->assertCount(1, $reads, 'read_instances should only include the authenticated users reads');
        // Rating is doubled by the model accessor, so the owner's rating=5 is stored/returned as 10.
        $this->assertSame(10, $reads[0]['rating']);
    }

    public function test_store_creates_list_with_slug_and_user_id(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/lists', ['name' => 'Want to Read']);

        $response->assertCreated()->assertJsonStructure(['list_id', 'name', 'slug', 'user_id']);
        $this->assertSame('Want to Read', $response->json('name'));
        $this->assertSame('want-to-read', $response->json('slug'));
        $this->assertDatabaseHas('lists', [
            'list_id' => $response->json('list_id'),
            'name' => 'Want to Read',
            'slug' => 'want-to-read',
            'user_id' => $user->user_id,
        ]);
    }

    public function test_store_validates_name(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/lists', [])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_update_renames_list_and_regenerates_slug(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $response = $this->putJson("/api/lists/{$list->list_id}", ['name' => 'New Name']);

        $response->assertOk();
        $this->assertDatabaseHas('lists', [
            'list_id' => $list->list_id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
    }

    public function test_destroy_deletes_list_and_cascades_items(): void
    {
        $user = $this->actingAsUser();
        $list = BookList::factory()->forUser($user)->create();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $item = ListItem::factory()->create([
            'list_id' => $list->list_id,
            'version_id' => $version->version_id,
            'ordinal' => 0,
        ]);

        $this->deleteJson("/api/lists/{$list->list_id}")->assertNoContent();

        $this->assertDatabaseMissing('lists', ['list_id' => $list->list_id]);
        $this->assertDatabaseMissing('list_items', ['list_item_id' => $item->list_item_id]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/lists')->assertUnauthorized();
        $this->postJson('/api/lists', ['name' => 'X'])->assertUnauthorized();
    }
}
