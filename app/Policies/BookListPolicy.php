<?php

namespace App\Policies;

use App\Models\BookList;
use App\Models\User;

class BookListPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BookList $list): bool
    {
        return $user->user_id === $list->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BookList $list): bool
    {
        return $user->user_id === $list->user_id;
    }

    public function delete(User $user, BookList $list): bool
    {
        return $user->user_id === $list->user_id;
    }
}
