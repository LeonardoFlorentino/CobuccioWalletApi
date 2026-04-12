<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_return_standardized_401_payload(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('status', 401);
    }

    public function test_validation_errors_return_standardized_422_payload(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/transactions?type=invalid-type');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('status', 422)
            ->assertJsonStructure(['errors' => ['type']]);
    }

    public function test_missing_model_returns_standardized_404_payload(): void
    {
        $user = User::factory()->create([
            'user_type' => 'regular',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/transactions/999999');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Resource not found.')
            ->assertJsonPath('status', 404);
    }
}
