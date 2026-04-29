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

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }
}
