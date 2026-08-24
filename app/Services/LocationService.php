<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Version;
use App\Services\Exceptions\LocationCodeConflictException;
use App\Services\Exceptions\LocationCycleException;
use App\Services\Exceptions\LocationHasChildrenException;
use App\Services\Exceptions\LocationInUseException;
use App\Support\Slugger;

/**
 * Single owner of the location rules: code identity, tree integrity, and
 * what deleting a place means for the copies on it.
 *
 * Locations are shared catalog (like genres and formats, not like lists), so
 * nothing in here is user-scoped. The slug derives from the code and is what
 * routes and the CSV importer match, so a location can be renamed freely but
 * recoding one moves its identity — `update()` re-derives the slug when the
 * code changes.
 */
class LocationService
{
    /** Trim and collapse internal whitespace — same spelling rule genres apply. */
    public static function normalizeCode(string $code): string
    {
        return trim(preg_replace('/\s+/u', ' ', $code));
    }

    /**
     * The location already holding this code (via its slug — comparison is
     * effectively case-insensitive, 'o1s5' and 'O1S5' are one place), or null.
     */
    public function findConflict(string $code, ?Location $except = null): ?Location
    {
        $query = Location::where('slug', Slugger::for($code));

        if ($except !== null) {
            $query->where('location_id', '!=', $except->location_id);
        }

        return $query->first();
    }

    /**
     * @param  array{code: string, name?: ?string, kind: string, parent_id?: ?int, ordinal?: ?int}  $attributes
     *
     * @throws LocationCodeConflictException
     */
    public function create(array $attributes): Location
    {
        $code = self::normalizeCode($attributes['code']);

        $conflict = $this->findConflict($code);
        if ($conflict !== null) {
            throw new LocationCodeConflictException($conflict);
        }

        return Location::create([
            'code' => $code,
            'slug' => Slugger::for($code),
            'name' => $attributes['name'] ?? null,
            'kind' => $attributes['kind'],
            'parent_id' => $attributes['parent_id'] ?? null,
            'ordinal' => $attributes['ordinal'] ?? null,
        ]);
    }

    /**
     * Apply whichever of code / name / kind / ordinal / parent_id the caller
     * sent. A `parent_id` change is a move and gets the cycle guard; a `code`
     * change re-derives the slug, which changes the location's URL and CSV
     * identity — that is the point of recoding.
     *
     * @param  array<string, mixed>  $attributes  only keys present are applied
     *
     * @throws LocationCodeConflictException
     * @throws LocationCycleException
     */
    public function update(Location $location, array $attributes): Location
    {
        if (array_key_exists('code', $attributes)) {
            $code = self::normalizeCode((string) $attributes['code']);

            $conflict = $this->findConflict($code, $location);
            if ($conflict !== null) {
                throw new LocationCodeConflictException($conflict);
            }

            $location->code = $code;
            $location->slug = Slugger::for($code);
        }

        if (array_key_exists('parent_id', $attributes)) {
            $this->guardMove($location, $attributes['parent_id']);
            $location->parent_id = $attributes['parent_id'];
        }

        foreach (['name', 'kind', 'ordinal'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $location->{$key} = $attributes[$key];
            }
        }

        $location->save();

        return $location;
    }

    /**
     * The location a code names, created (with its ancestor chain) when it
     * doesn't exist yet.
     *
     * This is the `create_locations` import path only — every other door
     * treats an unknown code as an error. A code matching the shelf
     * convention (`O1S5`) rebuilds room → bookcase → shelf exactly the way
     * the backfill migration did, which is what makes a database reset
     * recover the tree's shape from the CSV alone; anything else lands as a
     * root location so no structure is guessed. Names ('Office') are not in
     * the CSV and come back null.
     */
    public function findOrCreateByCode(string $code): Location
    {
        $code = self::normalizeCode($code);

        $existing = $this->findConflict($code);
        if ($existing !== null) {
            return $existing;
        }

        if (preg_match('/^([A-Z])(\d+)S(\d+)$/', strtoupper($code), $parts) === 1) {
            [, $letter, $bookcaseNumber, $shelfNumber] = $parts;

            $room = $this->findConflict($letter) ?? Location::create([
                'code' => $letter,
                'kind' => 'room',
                'slug' => Slugger::for($letter),
            ]);

            $bookcaseCode = $letter.$bookcaseNumber;
            $bookcase = $this->findConflict($bookcaseCode) ?? Location::create([
                'code' => $bookcaseCode,
                'kind' => 'bookcase',
                'parent_id' => $room->location_id,
                'slug' => Slugger::for($bookcaseCode),
                'ordinal' => (int) $bookcaseNumber,
            ]);

            return Location::create([
                'code' => strtoupper($code),
                'kind' => 'shelf',
                'parent_id' => $bookcase->location_id,
                'slug' => Slugger::for($code),
                'ordinal' => (int) $shelfNumber,
            ]);
        }

        return Location::create([
            'code' => $code,
            'kind' => 'shelf',
            'slug' => Slugger::for($code),
        ]);
    }

    /**
     * @throws LocationCycleException when the new parent is the location itself or a descendant
     */
    private function guardMove(Location $location, ?int $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }

        if (in_array($newParentId, $location->subtreeIds(), true)) {
            throw new LocationCycleException;
        }
    }

    /**
     * @throws LocationHasChildrenException always refused — structure is deleted leaf-first, explicitly
     * @throws LocationInUseException when copies are shelved here and `$force` is false
     */
    public function delete(Location $location, bool $force): void
    {
        $childrenCount = $location->children()->count();
        if ($childrenCount > 0) {
            throw new LocationHasChildrenException($childrenCount);
        }

        $versionsCount = $location->versions()->count();
        if ($versionsCount > 0 && ! $force) {
            throw new LocationInUseException($versionsCount);
        }

        // Explicit rather than leaning on the FK's `set null`: the versions
        // become unshelved, and a stale shelf_ordinal on an unshelved copy
        // would be a position on a shelf that no longer exists.
        $location->versions()->update(['location_id' => null, 'shelf_ordinal' => null]);

        $location->delete();
    }

    /**
     * Put a copy somewhere (or nowhere — null unshelves). Reshelving is a
     * single FK update; that being cheap is half the reason locations exist.
     */
    public function shelveVersion(Version $version, ?int $locationId, ?int $shelfOrdinal = null): Version
    {
        $version->fill([
            'location_id' => $locationId,
            // An ordinal is a position *on a shelf*; without a shelf it is
            // meaningless, so unshelving always clears it.
            'shelf_ordinal' => $locationId === null ? null : $shelfOrdinal,
        ])->save();

        return $version;
    }
}
