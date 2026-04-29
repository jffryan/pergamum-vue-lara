<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_authenticate_the_user(): void
    {
        $user = User::factory()->create([
            'email' => 'reader@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->get(route('sanctum.csrf-cookie'))->assertNoContent();

        $response = $this->postJson('/login', [
            'email' => 'reader@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_returns_401_and_does_not_authenticate(): void
    {
        User::factory()->create([
            'email' => 'reader@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->postJson('/login', [
            'email' => 'reader@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401)->assertJsonStructure(['message']);
        $this->assertGuest();
    }

    public function test_unknown_email_returns_401(): void
    {
        $response = $this->postJson('/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401);
        $this->assertGuest();
    }

    public function test_missing_fields_return_422(): void
    {
        $this->postJson('/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_malformed_email_returns_422(): void
    {
        $this->postJson('/login', [
            'email' => 'not-an-email',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_csrf_cookie_endpoint_seats_xsrf_token(): void
    {
        $response = $this->get(route('sanctum.csrf-cookie'));

        $response->assertNoContent();
        $this->assertNotNull($response->getCookie('XSRF-TOKEN', false));
    }
}
