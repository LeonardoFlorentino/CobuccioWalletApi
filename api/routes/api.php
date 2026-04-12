<?php

use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    // User routes
    Route::get('/user', [UserController::class, 'getUser']);
    Route::get('/user/balance', [UserController::class, 'getBalance']);

    // Transaction routes
    Route::post('/transactions/deposit', [TransactionController::class, 'deposit']);
    Route::post('/transactions/transfer', [TransactionController::class, 'transfer']);
    Route::post('/transactions/reverse', [TransactionController::class, 'reverse']);
    Route::get('/transactions', [TransactionController::class, 'getUserTransactions']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
});
