<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::factory()->create();

        $this->actingAs($user, 'sanctum');

        return $user;
    }
}
