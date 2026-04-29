<?php

namespace Tests\Feature\Books;

use App\Models\Book;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddReadInstanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_read_instance_persists_for_book_and_version(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $response = $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => '2025-02-14',
                'rating' => 4,
            ],
        ]);

        $response->assertOk();
        $this->assertSame($user->user_id, $response->json('user_id'));
        $this->assertDatabaseHas('read_instances', [
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'user_id' => $user->user_id,
        ]);
        $this->assertCount(1, $version->readInstances()->get());
        $this->assertCount(1, $book->readInstances()->get());
    }

    public function test_add_read_instance_returns_404_when_book_missing(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $response = $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => 999_999,
                'version_id' => $version->version_id,
                'date_read' => '2025-01-01',
                'rating' => 3,
            ],
        ]);

        $response->assertNotFound();
    }

    public function test_add_read_instance_returns_404_when_version_missing(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();

        $response = $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => $book->book_id,
                'version_id' => 999_999,
                'date_read' => '2025-01-01',
                'rating' => 3,
            ],
        ]);

        $response->assertNotFound();
    }
}
