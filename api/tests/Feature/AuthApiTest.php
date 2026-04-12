<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'User registered successfully.')
            ->assertJsonPath('user.email', 'john@example.com')
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'user_type' => 'regular',
        ]);
    }

    public function test_user_can_login_with_valid_credentials_and_receive_token(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'password123',
            'user_type' => 'regular',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('token_type', 'Bearer');
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'password123',
            'user_type' => 'regular',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'john@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_authenticated_user_can_get_me(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('message', 'Authenticated user retrieved successfully.')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_unauthenticated_user_cannot_get_me(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_logout_and_revoke_current_token(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        $plainTextToken = $user->createToken('auth-token')->plainTextToken;
        $token = explode('|', $plainTextToken)[1];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout');

        $response->assertOk()
            ->assertJsonPath('message', 'Logout successful.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
