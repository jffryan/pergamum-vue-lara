<?php

namespace Tests\Feature\Books;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read-history half of `PUT /api/books/{id}`.
 *
 * The edit form round-trips whatever `read_instances` the show payload gave
 * it, so these tests use the serialized key (`read_instance_id`) rather than
 * a hand-written one — the mismatch between that and the model's declared
 * primary key is exactly what used to make edits vanish.
 */
class UpdateReadInstancesTest extends TestCase
{
    use RefreshDatabase;

    private function payloadFor(Book $book, array $readInstances): array
    {
        return [
            'request' => [
                'formData' => [
                    'book' => ['title' => $book->title],
                    'authors' => [],
                    'genres' => [],
                    'readInstances' => $readInstances,
                ],
            ],
        ];
    }

    public function test_editing_a_rating_persists(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $read = ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2024-03-10',
            'rating' => 3,
        ]);

        $response = $this->putJson("/api/books/{$book->book_id}", $this->payloadFor($book, [
            [
                'read_instance_id' => $read->read_instance_id,
                'date_read' => '2024-03-10',
                'rating' => 5,
            ],
        ]));

        $response->assertOk();
        // Stored doubled — see the rating mutator in /documentation/books.md.
        $this->assertDatabaseHas('read_instances', [
            'read_instance_id' => $read->read_instance_id,
            'rating' => 10,
        ]);
        $this->assertSame(1, ReadInstance::count(), 'the edit must not duplicate the row');
    }

    public function test_editing_a_date_persists(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $read = ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2024-03-10',
            'rating' => 3,
        ]);

        $this->putJson("/api/books/{$book->book_id}", $this->payloadFor($book, [
            [
                'read_instance_id' => $read->read_instance_id,
                'date_read' => '2025-01-02',
                'rating' => 3,
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('read_instances', [
            'read_instance_id' => $read->read_instance_id,
            'date_read' => '2025-01-02',
        ]);
    }

    public function test_another_users_read_instance_cannot_be_edited(): void
    {
        $this->actingAsUser();
        $other = User::factory()->create();

        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $theirs = ReadInstance::factory()->forUser($other)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2024-03-10',
            'rating' => 2,
        ]);

        $this->putJson("/api/books/{$book->book_id}", $this->payloadFor($book, [
            [
                'read_instance_id' => $theirs->read_instance_id,
                'date_read' => '2024-03-10',
                'rating' => 5,
            ],
        ]));

        $this->assertDatabaseHas('read_instances', [
            'read_instance_id' => $theirs->read_instance_id,
            'rating' => 4,
        ]);
    }

    /**
     * The model's primary key has to name a real column, or `find()`,
     * `getKey()` and route-model binding all query a column that isn't there.
     */
    public function test_a_read_instance_can_be_found_by_its_primary_key(): void
    {
        $user = $this->actingAsUser();
        $book = Book::factory()->create();
        $version = Version::factory()->for($book, 'book')->create();
        $read = ReadInstance::factory()->forUser($user)->create([
            'book_id' => $book->book_id,
            'version_id' => $version->version_id,
            'date_read' => '2024-03-10',
        ]);

        $found = ReadInstance::find($read->getKey());

        $this->assertNotNull($found);
        $this->assertSame($read->read_instance_id, $found->read_instance_id);
    }
}
