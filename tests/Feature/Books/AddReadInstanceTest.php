<?php

namespace Tests\Feature\Books;

use App\Models\Book;
use App\Models\ReadInstance;
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

    /**
     * A book_id that names nothing is a bad *field*, not a missing resource —
     * the URL resolved fine. This used to `findOrFail` into a 404; since the
     * checks moved into `StoreReadInstanceRequest` every bad key in the body
     * reports the same way, as a 422 naming the field.
     */
    public function test_add_read_instance_rejects_unknown_book_id(): void
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

        $response->assertStatus(422)->assertJsonValidationErrors('readInstance.book_id');
        $this->assertSame(0, ReadInstance::count());
    }

    public function test_add_read_instance_rejects_rating_out_of_range(): void
    {
        $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();

        $response = $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => $book->book_id,
                'version_id' => $version->version_id,
                'date_read' => '2025-02-14',
                'rating' => 9,
            ],
        ]);

        // Matches how bulk upload reports the same rejection.
        $response->assertStatus(422)
            ->assertJsonPath('reason_code', 'rating_out_of_range')
            ->assertJsonValidationErrors('readInstance.rating');
        $this->assertSame(0, ReadInstance::count());
    }

    public function test_add_read_instance_rejects_version_from_different_book(): void
    {
        $this->actingAsUser();
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $versionOfB = Version::factory()->for($bookB, 'book')->create();

        $response = $this->postJson('/api/add-read-instance', [
            'readInstance' => [
                'book_id' => $bookA->book_id,
                'version_id' => $versionOfB->version_id,
                'date_read' => '2025-02-14',
                'rating' => 4,
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame('version_book_mismatch', $response->json('reason_code'));
        $this->assertSame(0, ReadInstance::count());
    }

    public function test_read_instance_model_rejects_cross_book_version_on_save(): void
    {
        $user = $this->actingAsUser();
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $versionOfB = Version::factory()->for($bookB, 'book')->create();

        $instance = new ReadInstance([
            'user_id' => $user->user_id,
            'book_id' => $bookA->book_id,
            'version_id' => $versionOfB->version_id,
            'date_read' => '2025-02-14',
            'rating' => 4,
        ]);

        $this->expectException(\DomainException::class);

        try {
            $instance->save();
        } finally {
            $this->assertSame(0, ReadInstance::count());
        }
    }

    public function test_add_read_instance_rejects_unknown_version_id(): void
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

        $response->assertStatus(422)->assertJsonValidationErrors('readInstance.version_id');
        $this->assertSame(0, ReadInstance::count());
    }
}
