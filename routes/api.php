<?php

use App\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;

Route::prefix('clients')->name('clients.')->group(function () {
    Route::get('/', [ClientController::class, 'index']);
    Route::get('/{client}/websites', [ClientController::class, 'show']);
});
