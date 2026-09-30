<?php

namespace App\Policies;

use App\Models\Author;
use App\Models\User;

/**
 * Every ability returns `true`, deliberately — the same reasoning as
 * {@see GenrePolicy}. Authors are part of the shared catalog and there is no
 * admin privilege level yet; this class is the seam a gate would land on.
 */
class AuthorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Author $author): bool
    {
        return true;
    }

    public function merge(User $user, Author $author): bool
    {
        return true;
    }
}
