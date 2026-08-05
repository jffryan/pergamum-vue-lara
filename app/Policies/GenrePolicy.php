<?php

namespace App\Policies;

use App\Models\Genre;
use App\Models\User;

/**
 * Every ability returns `true`, deliberately.
 *
 * Pergamum is a shared catalog with per-user reading state (CHANGELOG 0.1.7),
 * so there is no admin privilege level to check against — genre management is
 * open to any authenticated user, same as every other `/admin` action. This
 * class exists as the seam: if `/feature-plans/admin.md` items 1–2 ever land an
 * `is_admin` gate, it changes here rather than across six call sites.
 *
 * No `GenrePolicyTest` accompanies this — with no ability that can return false
 * there is no negative case to pin. Write one when a gate exists.
 */
class GenrePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Genre $genre): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Genre $genre): bool
    {
        return true;
    }

    public function delete(User $user, Genre $genre): bool
    {
        return true;
    }

    /**
     * Not one of the seven resource verbs, so `authorizeResource()` doesn't map
     * it — `GenreController::merge()` calls `Gate::authorize()` explicitly.
     */
    public function merge(User $user, Genre $genre): bool
    {
        return true;
    }
}
