<?php

namespace Database\Factories;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    protected $model = Book::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }

    public function withAuthors(int $count = 1): static
    {
        return $this->afterCreating(function (Book $book) use ($count) {
            $authors = Author::factory()->count($count)->create();
            $pivot = [];
            foreach ($authors as $i => $author) {
                $pivot[$author->author_id] = ['author_ordinal' => $i + 1];
            }
            $book->authors()->attach($pivot);
        });
    }

    public function withGenres(int $count = 1): static
    {
        return $this->afterCreating(function (Book $book) use ($count) {
            $genres = Genre::factory()->count($count)->create();
            $book->genres()->attach($genres->pluck('genre_id')->all());
        });
    }

    public function withVersion(array $overrides = []): static
    {
        return $this->afterCreating(function (Book $book) use ($overrides) {
            Version::factory()->for($book, 'book')->create($overrides);
        });
    }
}
