<?php

namespace Database\Factories;

use App\Models\Format;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Format>
 */
class FormatFactory extends Factory
{
    protected $model = Format::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Hardcover', 'Paperback', 'Audiobook', 'Ebook']);

        // The flags are closures over the resolved `name` so that a test asking
        // for `['name' => 'Audiobook']` gets a format that behaves like one
        // without having to restate the capabilities. `name` must stay declared
        // first — `expandAttributes()` resolves closures in array order.
        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'expects_page_count' => fn (array $attributes) => $attributes['name'] !== 'Audiobook',
            'expects_audio_runtime' => fn (array $attributes) => $attributes['name'] === 'Audiobook',
        ];
    }

    /**
     * A format measured in minutes rather than pages, whatever it is called.
     */
    public function audio(): static
    {
        return $this->state(fn () => [
            'expects_page_count' => false,
            'expects_audio_runtime' => true,
        ]);
    }

    public function print(): static
    {
        return $this->state(fn () => [
            'expects_page_count' => true,
            'expects_audio_runtime' => false,
        ]);
    }
}
