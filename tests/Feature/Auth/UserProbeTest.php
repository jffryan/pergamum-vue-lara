<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_request_returns_user_payload(): void
    {
        $user = $this->actingAsUser(User::factory()->create([
            'name' => 'Probed User',
            'email' => 'probe@example.com',
        ]));

        $response = $this->getJson('/api/user');

        $response->assertOk()->assertJson([
            'user_id' => $user->user_id,
            'name' => 'Probed User',
            'email' => 'probe@example.com',
        ]);

        $response->assertJsonMissing(['password' => $user->password]);
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('remember_token', $response->json());

        $this->assertKeyAbsentRecursively($response->json(), 'password');
        $this->assertKeyAbsentRecursively($response->json(), 'remember_token');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    private function assertKeyAbsentRecursively($payload, string $key, string $path = '$'): void
    {
        if (is_array($payload)) {
            foreach ($payload as $k => $v) {
                $childPath = $path.'.'.$k;
                $this->assertNotSame($key, (string) $k, "Sensitive key '{$key}' found at {$childPath}");
                $this->assertKeyAbsentRecursively($v, $key, $childPath);
            }
        }
    }
}
