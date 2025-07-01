<?php

use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware('guest')
            ->name('register');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('guest')
            ->name('login');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->middleware('auth:sanctum')
            ->name('logout');


// Rotas que exigem autenticação
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // NOSSA ROTA PARA OCR
    Route::post('/ocr-upload', [OcrController::class, 'processarImagem']);
});