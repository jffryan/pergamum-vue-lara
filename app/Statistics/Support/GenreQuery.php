<?php

namespace App\Statistics\Support;

use App\Statistics\Scope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The shared genre-membership queries — {@see ListQuery}'s sibling.
 *
 * A genre tags *books*, not copies, so every copy of a tagged book is in
 * scope: the paperback and the audiobook of a history are both history.
 */
class GenreQuery
{
    /**
     * Every version of every book carrying the genre.
     */
    public static function items(Scope $scope): Builder
    {
        return DB::table('versions')
            ->whereIn('versions.book_id', static::bookIds($scope));
    }

    /**
     * The books carrying the genre, as a subquery. Straight off the pivot
     * rather than through `items()`, so a tagged book with no copies yet
     * still counts towards the genre.
     */
    public static function bookIds(Scope $scope): Builder
    {
        return DB::table('book_genre')
            ->select('book_genre.book_id')
            ->where('book_genre.genre_id', $scope->id);
    }
}
