<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes  (prefix /api, token auth with Laravel Sanctum)
|--------------------------------------------------------------------------
|
| People create a token under My account → API tokens (needs the
| "self.api" permission), then call:
|
|   curl -H "Authorization: Bearer <token>" -H "Accept: application/json" \
|        https://your-app/api/v1/me
|
| A token can only do what its owner can do: protect every route with
| ->middleware('can:<permission>') exactly like the web routes.
*/

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->name('api.')->group(function () {
    Route::get('me', [Api\MeController::class, 'show'])->name('me');

    Route::get('users', [Api\UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::get('users/{user}', [Api\UserController::class, 'show'])->middleware('can:users.view')->name('users.show');

    foreach (glob(__DIR__.'/modules/api/*.php') ?: [] as $moduleRoutes) {
        require $moduleRoutes;
    }
});
