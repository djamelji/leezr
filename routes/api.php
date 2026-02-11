<?php

use App\Core\Auth\AuthController;
use Illuminate\Support\Facades\Route;

// Public (no auth)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Authenticated (auth:sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/my-companies', [AuthController::class, 'myCompanies']);
});
