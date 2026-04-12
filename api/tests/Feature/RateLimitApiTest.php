<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_routes_are_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'password123',
            'user_type' => 'regular',
        ]);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])
                ->postJson('/api/auth/login', [
                    'email' => 'john@example.com',
                    'password' => 'wrong-password',
                ]);

            $this->assertContains($response->status(), [401, 422]);
        }

        $blockedResponse = $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])
            ->postJson('/api/auth/login', [
                'email' => 'john@example.com',
                'password' => 'wrong-password',
            ]);

        $blockedResponse->assertStatus(429)
            ->assertJsonPath('status', 429);
    }

    public function test_wallet_transaction_routes_are_rate_limited(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '10.2.2.2'])
                ->getJson('/api/transactions');

            $response->assertOk();
        }

        $blockedResponse = $this->withServerVariables(['REMOTE_ADDR' => '10.2.2.2'])
            ->getJson('/api/transactions');

        $blockedResponse->assertStatus(429)
            ->assertJsonPath('status', 429);
    }
}
