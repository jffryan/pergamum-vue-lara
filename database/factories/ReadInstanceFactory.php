<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadInstance>
 */
class ReadInstanceFactory extends Factory
{
    protected $model = ReadInstance::class;

    public function definition(): array
    {
        $version = Version::factory()->create();

        return [
            'user_id' => User::factory(),
            'book_id' => $version->book_id,
            'version_id' => $version->version_id,
            'date_read' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'rating' => fake()->optional(0.7)->numberBetween(1, 5),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->user_id]);
    }
}
