<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    // Auth routes
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // User routes
    Route::get('/user', [UserController::class, 'getUser']);
    Route::get('/user/balance', [UserController::class, 'getBalance']);

    Route::middleware(['throttle:wallet'])->group(function () {
        // Transaction routes
        Route::post('/transactions/deposit', [TransactionController::class, 'deposit']);
        Route::post('/transactions/transfer', [TransactionController::class, 'transfer']);
        Route::post('/transactions/reverse', [TransactionController::class, 'reverse']);
        Route::get('/transactions', [TransactionController::class, 'getUserTransactions']);
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    });
});
