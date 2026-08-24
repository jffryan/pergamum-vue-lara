<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        // Shelf-shaped code with a unique suffix so factory bursts don't
        // collide on the unique slug (see backend-tests.md, Factories).
        $code = 'T'.fake()->unique()->numberBetween(1, 99999).'S1';

        return [
            'parent_id' => null,
            'code' => $code,
            'name' => null,
            'kind' => 'shelf',
            'slug' => strtolower($code),
            'ordinal' => null,
        ];
    }

    public function kind(string $kind): static
    {
        return $this->state(fn () => ['kind' => $kind]);
    }

    public function childOf(Location $parent): static
    {
        return $this->state(fn () => ['parent_id' => $parent->location_id]);
    }
}
