<?php

use App\Http\Controllers\Api\PunchImportController;
use Illuminate\Support\Facades\Route;

/*
| Punches from tools/lan-bridge (a PC on the office network that reads the device
| over the LAN). Needs an API token whose owner has "imports.manage".
*/
Route::post('punches/import', PunchImportController::class)->middleware('can:imports.manage')->name('punches.import');
