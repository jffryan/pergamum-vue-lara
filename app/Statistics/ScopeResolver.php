<?php

namespace App\Statistics;

use App\Models\BookList;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Scope type string -> resolved, authorized {@see Scope}.
 *
 * Adding a surface for authors, genres or formats is a case here plus a
 * `supports()` on the metrics — no route churn, no new controller.
 */
class ScopeResolver
{
    public function resolve(?string $type, ?string $id = null): Scope
    {
        $type = $type ?: Scope::USER;
        $userId = auth()->id();

        return match ($type) {
            // `auth:sanctum` is the whole authorization story for a user's
            // own statistics; there is nothing further to check.
            Scope::USER => new Scope(Scope::USER, $userId),
            Scope::LIST => $this->list($id, $userId),
            default => throw new NotFoundHttpException("Unknown statistics scope [{$type}]."),
        };
    }

    /**
     * A list someone else owns is a 403, not an empty statistics page — the
     * existing `BookListPolicy` decides, so there is one rule rather than two.
     */
    private function list(?string $id, ?int $userId): Scope
    {
        $list = BookList::find($id);

        if ($list === null) {
            throw new NotFoundHttpException("Unknown list [{$id}].");
        }

        Gate::authorize('view', $list);

        return new Scope(Scope::LIST, $userId, (int) $list->list_id, $list);
    }
}
