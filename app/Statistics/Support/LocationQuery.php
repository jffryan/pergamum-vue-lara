<?php

namespace App\Statistics\Support;

use App\Models\Location;
use App\Statistics\Scope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The shared location-membership queries — {@see ListQuery}'s sibling.
 *
 * A location holds *versions* directly (`versions.location_id`), so there is
 * no membership table to join; the interesting part is the subtree. One
 * scope serves shelf, bookcase, and room alike: the subtree is just wider,
 * which is how a room gets statistics for free.
 */
class LocationQuery
{
    /**
     * Every version anywhere in the scope's subtree.
     */
    public static function items(Scope $scope): Builder
    {
        return DB::table('versions')
            ->whereIn('versions.location_id', self::subtreeIds($scope));
    }

    /**
     * The distinct books the subtree covers, as a subquery — two copies of
     * one novel on one shelf are one book.
     */
    public static function bookIds(Scope $scope): Builder
    {
        return static::items($scope)->select('versions.book_id')->distinct();
    }

    /**
     * @return array<int, int>
     */
    private static function subtreeIds(Scope $scope): array
    {
        /** @var Location $location */
        $location = $scope->model;

        return $location->subtreeIds();
    }
}
