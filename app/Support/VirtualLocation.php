<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A place that is not a row in `locations` but is still somewhere a copy
 * can be: the unshelved holding pen, the discarded pile.
 *
 * Both are *derived* from the version's own columns rather than stored as a
 * `location_id` — a copy is unshelved because it has no location, discarded
 * because `is_discarded` says so. Deriving is what keeps them synced: there
 * is no second fact to drift, so discarding a copy puts it in the pile by
 * definition and nothing has to remember to move it. See
 * {@see VirtualLocations} for the registry.
 */
final class VirtualLocation
{
    /**
     * @param  Closure(Builder): Builder  $constraint  narrows a query over `versions` to this place's copies
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $code,
        public readonly string $name,
        public readonly string $description,
        private readonly Closure $constraint,
    ) {}

    /**
     * Narrow a query whose rows are `versions` to the copies held here.
     * Works on either builder flavour — the listing uses Eloquent, the
     * counts use the query builder.
     */
    public function constrain(Builder $versions): Builder
    {
        return ($this->constraint)($versions);
    }

    /**
     * The same shape as a `Location` row in the index payload, so the SPA's
     * location page can render either without branching on more than the
     * `virtual` flag. `location_id` is null on purpose: nothing can be
     * shelved *onto* a virtual location.
     */
    public function toArray(int $versionsCount): array
    {
        return [
            'location_id' => null,
            'parent_id' => null,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => VirtualLocations::KIND,
            'slug' => $this->slug,
            'ordinal' => null,
            'virtual' => true,
            'description' => $this->description,
            'versions_count' => $versionsCount,
        ];
    }
}
