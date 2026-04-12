<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Get authenticated user information.
     */
    public function getUser(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'message' => 'User retrieved successfully.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'balance' => $user->balance,
                'user_type' => $user->user_type,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ], 200);
    }

    /**
     * Get user balance.
     */
    public function getBalance(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'message' => 'Balance retrieved successfully.',
            'balance' => $user->balance,
        ], 200);
    }
}
