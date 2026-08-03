<?php

namespace App\Statistics\Support;

use App\Statistics\Scope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The shared list-membership queries.
 *
 * A list holds *versions*, not books, which is why "how many books is this"
 * and "how many pages is this" are different questions over the same rows.
 * Both start here so the distinction is made once, visibly.
 */
class ListQuery
{
    /**
     * Every item on the list, joined to the version it points at.
     */
    public static function items(Scope $scope): Builder
    {
        return DB::table('list_items')
            ->join('versions', 'list_items.version_id', '=', 'versions.version_id')
            ->where('list_items.list_id', $scope->id);
    }

    /**
     * The distinct books the list covers, as a subquery — a paperback and an
     * audiobook of one novel are one book.
     */
    public static function bookIds(Scope $scope): Builder
    {
        return static::items($scope)->select('versions.book_id')->distinct();
    }
}
