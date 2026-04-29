<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_hashes_password_and_logs_in(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'New Reader',
            'email' => 'new@example.com',
            'password' => 'a-decent-password',
            'password_confirmation' => 'a-decent-password',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'name' => 'New Reader']);

        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user->user_id);
        $this->assertNotSame('a-decent-password', $user->password);
        $this->assertTrue(Hash::check('a-decent-password', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_register_does_not_set_email_verified_at(): void
    {
        $this->postJson('/register', [
            'name' => 'Unverified',
            'email' => 'unverified@example.com',
            'password' => 'a-decent-password',
            'password_confirmation' => 'a-decent-password',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'email' => 'unverified@example.com',
            'email_verified_at' => null,
        ]);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $this->postJson('/register', [
            'name' => 'Mismatch',
            'email' => 'mm@example.com',
            'password' => 'a-decent-password',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_register_password_must_be_at_least_8_chars(): void
    {
        $this->postJson('/register', [
            'name' => 'Shorty',
            'email' => 'shorty@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_register_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/register', [
            'name' => 'Dup',
            'email' => 'taken@example.com',
            'password' => 'a-decent-password',
            'password_confirmation' => 'a-decent-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_name_email_password(): void
    {
        $this->postJson('/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }
}
