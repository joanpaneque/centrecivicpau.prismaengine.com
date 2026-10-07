<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('password/force-change', [ForcePasswordChangeController::class, 'edit'])
        ->name('password.force-change');
    Route::put('password/force-change', [ForcePasswordChangeController::class, 'update'])
        ->name('password.force-change.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('panel', 'Dashboard')->name('dashboard');

    Route::middleware(['admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::redirect('/', '/admin/usuarios')->name('index');
        Route::get('/usuarios', [AdminUserController::class, 'index'])->name('users');
        Route::post('/usuarios', [AdminUserController::class, 'store'])->name('users.store');
        Route::patch('/usuarios/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::patch('/usuarios/{user}/password', [AdminUserController::class, 'resetPassword'])
            ->name('users.password');
        Route::patch('/usuarios/{user}/admin', [AdminUserController::class, 'updateAdmin'])
            ->name('users.admin');
        Route::delete('/usuarios/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
    });
});

require __DIR__.'/settings.php';
