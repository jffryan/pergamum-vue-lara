<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Format;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Version>
 */
class VersionFactory extends Factory
{
    protected $model = Version::class;

    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'format_id' => Format::factory(),
            'page_count' => fake()->numberBetween(80, 1200),
            'audio_runtime' => null,
            'nickname' => fake()->optional()->words(2, true),
            'is_discarded' => false,
            'discarded_at' => null,
        ];
    }

    /**
     * Pass `null` for the date to model the common case: we know the copy is
     * gone, we don't know when.
     */
    public function discarded(?string $discardedAt = null): static
    {
        return $this->state(fn () => [
            'is_discarded' => true,
            'discarded_at' => $discardedAt,
        ]);
    }

    public function withReadInstances(int $count = 1, array $overrides = []): static
    {
        return $this->afterCreating(function (Version $version) use ($count, $overrides) {
            ReadInstance::factory()
                ->count($count)
                ->state(fn () => array_merge([
                    'book_id' => $version->book_id,
                    'version_id' => $version->version_id,
                ], $overrides))
                ->create();
        });
    }
}
