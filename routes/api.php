<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\NotificationController;

Route::get('/test', fn () => response()->json([
    'success' => true,
    'message' => 'FoundIT API is working!'
]));

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    Route::get('/items', [ItemController::class, 'index']);
    Route::get('/items/{item}', [ItemController::class, 'show']);
    Route::post('/items', [ItemController::class, 'store']);
    Route::put('/items/{item}', [ItemController::class, 'update']);
    Route::delete('/items/{item}', [ItemController::class, 'destroy']);
    Route::post('/items/{item}/resolve', [ItemController::class, 'resolve']);
    Route::get('/items/{item}/matches', [ItemController::class, 'matches']);
    Route::post('/items/{item}/conversation', [\App\Http\Controllers\Api\ChatController::class, 'startFromItem']);
    Route::get('/my-reports', [ItemController::class, 'myReports']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);

    Route::get('/conversations', [\App\Http\Controllers\Api\ChatController::class, 'index']);
    Route::post('/conversations', [\App\Http\Controllers\Api\ChatController::class, 'start']);
    Route::get('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\ChatController::class, 'getMessages']);
    Route::post('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\ChatController::class, 'sendMessage']);
    Route::delete('/messages/{message}', [\App\Http\Controllers\Api\ChatController::class, 'destroyMessage']);
});

