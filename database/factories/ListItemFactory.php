<?php

namespace Database\Factories;

use App\Models\BookList;
use App\Models\ListItem;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListItem>
 */
class ListItemFactory extends Factory
{
    protected $model = ListItem::class;

    public function definition(): array
    {
        return [
            'list_id' => BookList::factory(),
            'version_id' => Version::factory(),
            'ordinal' => fake()->numberBetween(1, 100),
        ];
    }
}
