<?php

use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\PrintAgentController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::prefix('v1')->name('api.')->group(function () {
        Route::get('reservations', [ReservationController::class, 'index'])->middleware('ability:reservations:read')->name('reservations.index');
        Route::get('reservations/{uuid}', [ReservationController::class, 'show'])->middleware('ability:reservations:read')->name('reservations.show');
        Route::post('reservations', [ReservationController::class, 'store'])->middleware('ability:reservations:write')->name('reservations.store');
        Route::post('reservations/{uuid}/cancel', [ReservationController::class, 'cancel'])->middleware('ability:reservations:write')->name('reservations.cancel');
    });

    Route::prefix('inspeccion/v1')->name('api.inspection.')->middleware('ability:inspection')->group(function () {
        Route::get('trabajadores', [InspectionController::class, 'workers'])->name('workers');
        Route::get('registros', [InspectionController::class, 'records'])->name('records');
    });
});

// The Windows print program of the bar (authenticated with its own key, see PrintAgent).
Route::prefix('print-agent')->name('api.print-agent.')->middleware('throttle:600,1')->group(function () {
    Route::post('hello', [PrintAgentController::class, 'hello'])->name('hello');
    Route::post('jobs', [PrintAgentController::class, 'jobs'])->name('jobs');
    Route::post('jobs/{printJob:uuid}/ack', [PrintAgentController::class, 'ack'])->name('ack');
    Route::get('download', [PrintAgentController::class, 'download'])->name('download');
});
