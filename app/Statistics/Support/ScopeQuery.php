<?php

namespace App\Statistics\Support;

use App\Statistics\Scope;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Scope type -> the membership query that answers "which copies is this
 * about". Lists, locations and genres all resolve to versions, so the
 * items-shaped metrics dispatch here rather than each branching on the scope
 * type themselves — adding the next membership-bearing scope is a case in
 * two methods, not an edit across four metrics.
 *
 * Both arms return a builder whose rows expose `versions.*` columns, which
 * is the contract the metrics rely on.
 */
class ScopeQuery
{
    private const MEMBERSHIP_SCOPES = [Scope::LIST, Scope::LOCATION, Scope::GENRE];

    /**
     * Whether the scope narrows to a set of books at all — the user scope is
     * everything, and has no membership query.
     */
    public static function covers(Scope $scope): bool
    {
        return in_array($scope->type, self::MEMBERSHIP_SCOPES, true);
    }

    public static function items(Scope $scope): Builder
    {
        return match ($scope->type) {
            Scope::LIST => ListQuery::items($scope),
            Scope::LOCATION => LocationQuery::items($scope),
            Scope::GENRE => GenreQuery::items($scope),
            default => throw new RuntimeException("Scope [{$scope->type}] has no membership query."),
        };
    }

    public static function bookIds(Scope $scope): Builder
    {
        return match ($scope->type) {
            Scope::LIST => ListQuery::bookIds($scope),
            Scope::LOCATION => LocationQuery::bookIds($scope),
            Scope::GENRE => GenreQuery::bookIds($scope),
            default => throw new RuntimeException("Scope [{$scope->type}] has no membership query."),
        };
    }
}
