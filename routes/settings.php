<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('configuracion', fn () => redirect()->route('profile.edit'));

    Route::get('configuracion/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('configuracion/perfil', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('configuracion/perfil', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('configuracion/seguridad', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('configuracion/contrasena', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});
