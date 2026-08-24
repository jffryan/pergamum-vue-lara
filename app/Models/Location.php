<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A physical place a copy can be — a shelf, the bookcase it hangs in, the
 * room around it, or anything else (`kind` is an open vocabulary, so a box
 * or a "lent out" pile is a row, not a schema change).
 *
 * `code` is the machine identity ('O1S5'); `name` is the optional human
 * label ('Office — tall case, middle shelf'). The UI prefers `name` when
 * set; the CSV importer and the URL slug key on `code`.
 */
class Location extends Model
{
    use HasFactory;

    protected $primaryKey = 'location_id';

    protected $fillable = ['parent_id', 'code', 'name', 'kind', 'slug', 'ordinal'];

    /**
     * Slug-routed from day one — genres chose IDs and their plan carries an
     * item to undo it.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id')
            ->orderBy('ordinal')
            ->orderBy('code');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, 'location_id');
    }

    public function scopeKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * This location's id plus every descendant's, to any depth.
     *
     * One query for the whole (id, parent_id) edge list, walked in PHP — the
     * table is a couple of dozen rows, so a recursive CTE or closure table
     * would be machinery without a payoff. "Books in the office" is
     * `whereIn('location_id', $room->subtreeIds())`.
     *
     * @return array<int, int>
     */
    public function subtreeIds(): array
    {
        $edges = static::query()
            ->whereNotNull('parent_id')
            ->get(['location_id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [];
        $frontier = [$this->location_id];

        while ($frontier !== []) {
            $ids = array_merge($ids, $frontier);
            $next = [];
            foreach ($frontier as $id) {
                foreach ($edges->get($id, collect()) as $child) {
                    $next[] = $child->location_id;
                }
            }
            $frontier = $next;
        }

        return $ids;
    }

    /**
     * Root-first chain of parents, for breadcrumbs. Same one-query walk as
     * {@see subtreeIds()}, in the other direction.
     *
     * @return Collection<int, Location>
     */
    public function ancestors(): Collection
    {
        $all = static::query()->get()->keyBy('location_id');

        $chain = collect();
        $current = $this->parent_id === null ? null : $all->get($this->parent_id);

        while ($current !== null) {
            $chain->prepend($current);
            $current = $current->parent_id === null ? null : $all->get($current->parent_id);
        }

        return $chain;
    }
}
