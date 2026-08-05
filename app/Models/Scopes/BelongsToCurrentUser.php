<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrain a model to rows owned by the authenticated user.
 *
 * Pergamum is a shared catalog with per-user reading state: books, authors,
 * genres and formats are global, and `read_instances` is the row that is not.
 * That made `where('user_id', auth()->id())` a predicate every reader had to
 * remember, and two of them didn't — `AuthorService::getAuthorWithRelations`
 * and `GenreController::show` both eager-loaded read history unfiltered.
 *
 * Applying it here inverts the default: user scoping is what happens unless a
 * caller deliberately asks for otherwise.
 *
 * Cross-user reads are legitimate — statistics resolve their own subject, and
 * an export or an admin report would too — so they opt out by name:
 *
 *     ReadInstance::withoutGlobalScope(BelongsToCurrentUser::class)
 *
 * Only queries are affected. Writes still set `user_id` explicitly; the scope
 * has no opinion about who a new row belongs to.
 */
class BelongsToCurrentUser implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = auth()->id();

        // Falling back to "match nothing" would make an unauthenticated
        // context look like an empty library, and falling back to "match
        // everything" would silently undo the scope. Neither is a good way to
        // find out. A console caller that means it opts out by name.
        if ($userId === null) {
            throw new \RuntimeException(sprintf(
                '%s is scoped to the authenticated user and was queried with no session. '
                .'Call ->withoutGlobalScope(%s::class) and filter on user_id deliberately.',
                $model::class,
                self::class
            ));
        }

        // Qualified: read-derived queries join `versions`, and metrics select
        // against both tables. An unqualified column is one join away from
        // ambiguous.
        $builder->where($model->getTable().'.user_id', $userId);
    }
}
