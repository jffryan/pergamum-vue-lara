<?php

namespace App\Support;

use App\Models\Version;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The registry of {@see VirtualLocation}s — the places a copy can be that
 * aren't shelves.
 *
 * Two today. Adding a third ("lent out", say) is one entry here plus the
 * column it derives from; routes, the index payload, the show/books
 * endpoints and the SPA's locations page all read this list. The slugs are
 * reserved in `LocationService` so a real location can never shadow one —
 * the virtual routes are declared before the resource routes and would win
 * anyway, which is exactly why the collision has to be refused up front.
 */
final class VirtualLocations
{
    /** The `kind` every virtual location reports, in place of shelf / bookcase / room. */
    public const KIND = 'virtual';

    public const UNSHELVED = 'unshelved';

    public const DISCARDED = 'discarded';

    /**
     * @return array<int, VirtualLocation>
     */
    public static function all(): array
    {
        return [
            // The holding pen: copies you own that haven't been placed. This
            // is where a copy lands from every unshelve path — the picker's
            // "— Unshelved —", a forced shelf delete, a form-created version,
            // a CSV row with no location column.
            new VirtualLocation(
                slug: self::UNSHELVED,
                code: 'UNSHELVED',
                name: 'Unshelved',
                description: 'Copies you own that have not been placed on a shelf yet.',
                constraint: fn (Builder $q) => $q
                    ->whereNull('versions.location_id')
                    ->where('versions.is_discarded', false),
            ),
            // The pile: copies you no longer own. Discarding clears the
            // location (`VersionController::discard`) and shelving a
            // discarded copy is refused (`LocationService::shelveVersion`),
            // so membership here and membership on a shelf are exclusive.
            new VirtualLocation(
                slug: self::DISCARDED,
                code: 'DISCARDED',
                name: 'Discarded',
                description: 'Copies you no longer own. Discarding a copy takes it off its shelf; restoring one returns it to Unshelved.',
                constraint: fn (Builder $q) => $q->where('versions.is_discarded', true),
            ),
        ];
    }

    public static function find(string $slug): ?VirtualLocation
    {
        foreach (self::all() as $virtual) {
            if ($virtual->slug === $slug) {
                return $virtual;
            }
        }

        return null;
    }

    /** Whether a would-be location slug is one of ours and so off limits. */
    public static function isReserved(string $slug): bool
    {
        return self::find($slug) !== null;
    }

    /** `unshelved|discarded` — the `where()` pattern for the virtual routes. */
    public static function routePattern(): string
    {
        return implode('|', array_map(fn (VirtualLocation $virtual) => preg_quote($virtual->slug, '/'), self::all()));
    }

    /**
     * Every virtual location in index-payload shape, with its live count.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function toIndexRows(): array
    {
        return array_map(
            fn (VirtualLocation $virtual) => $virtual->toArray(
                $virtual->constrain(Version::query())->count(),
            ),
            self::all(),
        );
    }
}
