<?php

namespace Database\Factories;

use App\Models\ReadInstance;
use App\Models\User;
use App\Models\Version;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadInstance>
 */
class ReadInstanceFactory extends Factory
{
    protected $model = ReadInstance::class;

    /**
     * `book_id` / `version_id` resolve lazily so that whichever one the caller
     * supplies, the other is derived from it — no stray Version (and therefore
     * no stray Book) gets persisted behind an override. That leak used to make
     * literal row-count assertions impossible in stats tests.
     *
     * The two must always agree: ReadInstance::booted() throws on a
     * book/version mismatch, so they cannot be defaulted independently.
     *
     * `version_id` is declared before `book_id` deliberately. expandAttributes()
     * resolves closures in array order, so by the time `book_id` runs,
     * `version_id` is already a concrete value. In the other direction
     * `book_id` is still an unexpanded Closure, which is how the first callback
     * tells "caller supplied a book" from "caller supplied nothing".
     */
    public function definition(): array
    {
        $version = null;

        return [
            'user_id' => User::factory(),
            'version_id' => function (array $attributes) use (&$version) {
                $version = isset($attributes['book_id']) && ! $attributes['book_id'] instanceof Closure
                    ? Version::factory()->create(['book_id' => $attributes['book_id']])
                    : Version::factory()->create();

                return $version->version_id;
            },
            'book_id' => function (array $attributes) use (&$version) {
                // Set only when this factory just created the version above;
                // otherwise the caller supplied version_id and we read from it.
                if ($version !== null) {
                    return $version->book_id;
                }

                return Version::whereKey($attributes['version_id'])->value('book_id');
            },
            'date_read' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'rating' => fake()->optional(0.7)->numberBetween(1, 5),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->user_id]);
    }
}
