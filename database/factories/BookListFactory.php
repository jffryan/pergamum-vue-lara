<?php

namespace Database\Factories;

use App\Models\BookList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookList>
 */
class BookListFactory extends Factory
{
    protected $model = BookList::class;

    public function definition(): array
    {
        $name = fake()->unique()->sentence(2);

        return [
            'name' => rtrim($name, '.'),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->user_id]);
    }
}
