<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

/**
 * Every ability returns `true`, deliberately — the same seam as
 * {@see GenrePolicy}, for the same reason.
 *
 * Locations are shared catalog with no per-user ownership (a shelf is a
 * physical fact, not a curation), so there is no privilege level to check
 * yet. If `/feature-plans/admin.md` items 1–2 land an `is_admin` gate,
 * locations join genres behind it by editing this class alone.
 *
 * No `LocationPolicyTest` accompanies this — with no ability that can return
 * false there is no negative case to pin. Write one when a gate exists.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Location $location): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Location $location): bool
    {
        return true;
    }

    public function delete(User $user, Location $location): bool
    {
        return true;
    }
}
