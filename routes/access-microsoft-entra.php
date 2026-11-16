<?php

use Illuminate\Support\Facades\Route;
use TornadoEngine\AccessMicrosoftEntra\Http\EntraController;

Route::middleware(['web', 'auth', 'can:access.manage'])
    ->prefix('access/entra-id')
    ->name('access.entra.')
    ->group(function () {
        Route::get('/', [EntraController::class, 'index'])->name('index');
        Route::post('/connection', [EntraController::class, 'connection'])->name('connection');
        Route::post('/rules', [EntraController::class, 'store'])->name('rules.store');
        Route::delete('/rules/{rule}', [EntraController::class, 'destroy'])->name('rules.destroy');
        Route::post('/sync', [EntraController::class, 'sync'])->name('sync');
    });
