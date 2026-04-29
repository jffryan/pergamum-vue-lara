<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $db = config('database.connections.mysql.database');
        if (! str_contains($db, 'test')) {
            throw new \RuntimeException("Refusing to run tests against '{$db}' — name must contain 'test'.");
        }
    }


    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::factory()->create();

        $this->actingAs($user, 'sanctum');

        return $user;
    }
}
