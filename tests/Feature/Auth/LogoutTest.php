<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/logout');

        $response->assertOk()->assertJsonStructure(['message']);
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_for_subsequent_requests(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/logout')->assertOk();

        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_unauthenticated_logout_returns_401_for_json_clients(): void
    {
        $this->postJson('/logout')->assertStatus(401);
    }
}
