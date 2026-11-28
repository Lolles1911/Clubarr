<?php

use App\Http\Controllers\Api\V1Controller;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

// The REST API (docs/api.md). Prefixed /api by the framework.
Route::prefix('v1')->middleware(['throttle:clubarr-api', AuthenticateApiKey::class])->group(function () {
    Route::get('/status', [V1Controller::class, 'status']);
    Route::get('/members', [V1Controller::class, 'members']);
    Route::get('/invitations', [V1Controller::class, 'invitations']);
    Route::post('/invitations', [V1Controller::class, 'createInvitation'])->middleware('throttle:clubarr-api-write');
});
