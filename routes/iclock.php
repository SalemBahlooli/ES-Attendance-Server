<?php

use App\Http\Controllers\Attendance\IclockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ZKTeco ADMS ("Cloud Server") endpoints
|--------------------------------------------------------------------------
|
| The fingerprint device calls these by itself, so they sit outside the web
| group: no session, no CSRF token, no sign-in. Loaded from bootstrap/app.php.
|
| On the device: Menu → COMM → Cloud Server Setting → this server's address
| and port, HTTPS off.
*/

Route::prefix('iclock')->name('iclock.')->group(function () {
    Route::get('cdata', [IclockController::class, 'handshake'])->name('handshake');
    Route::post('cdata', [IclockController::class, 'upload'])->name('upload');
    Route::get('getrequest', [IclockController::class, 'getRequest'])->name('getrequest');
    Route::post('devicecmd', [IclockController::class, 'commandResult'])->name('devicecmd');
    Route::any('{any?}', [IclockController::class, 'other'])->where('any', '.*')->name('other');
});
