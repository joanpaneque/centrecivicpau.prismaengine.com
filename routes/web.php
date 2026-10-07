<?php

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\PrintingController;
use App\Http\Controllers\Admin\SetMenuController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\SupplierInvoiceController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\TimeTrackingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\ZoneController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\InitialSetupController;
use App\Http\Controllers\Auth\QrLoginController;
use App\Http\Controllers\InvoiceRequestController;
use App\Http\Controllers\Tpv\FloorController;
use App\Http\Controllers\Tpv\SessionController;
use App\Http\Controllers\Tpv\ShellController;
use App\Http\Controllers\Tpv\SyncController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('configuracion-inicial', [InitialSetupController::class, 'edit'])->name('initial-setup');
Route::post('configuracion-inicial', [InitialSetupController::class, 'store'])->name('initial-setup.store');

Route::get('acceso/{token}', QrLoginController::class)->middleware('throttle:20,1')->name('qr-login');

Route::post('idioma', [SessionController::class, 'locale'])->name('locale');

Route::middleware('throttle:30,1')->group(function () {
    Route::get('factura/{token}', [InvoiceRequestController::class, 'show'])->name('invoice-request');
    Route::post('factura/{token}', [InvoiceRequestController::class, 'store'])->middleware('throttle:5,1')->name('invoice-request.store');
    Route::get('factura/{token}/pdf', [InvoiceRequestController::class, 'pdf'])->name('invoice-request.pdf');
});

Route::middleware(['auth'])->group(function () {
    Route::get('password/force-change', [ForcePasswordChangeController::class, 'edit'])
        ->name('password.force-change');
    Route::put('password/force-change', [ForcePasswordChangeController::class, 'update'])
        ->name('password.force-change.update');
});

Route::middleware(['auth'])->prefix('tpv')->group(function () {
    Route::get('/', [ShellController::class, 'show'])->name('tpv');

    Route::prefix('api')->name('tpv.')->group(function () {
        Route::get('bootstrap', [SyncController::class, 'bootstrap'])->name('bootstrap');
        Route::get('pull', [SyncController::class, 'pull'])->name('pull');
        Route::post('push', [SyncController::class, 'push'])->name('push');
        Route::post('device', [ShellController::class, 'registerDevice'])->name('device');
        Route::post('switch-user', [SessionController::class, 'switchUser'])->middleware('throttle:30,1')->name('switch-user');

        Route::middleware('admin')->group(function () {
            Route::post('tables', [FloorController::class, 'store'])->name('tables.store');
            Route::patch('tables/{table}', [FloorController::class, 'update'])->name('tables.update');
            Route::delete('tables/{table}', [FloorController::class, 'destroy'])->name('tables.destroy');
            Route::post('layout', [FloorController::class, 'layout'])->name('tables.layout');
            Route::delete('zones/{zone}/auxiliary', [FloorController::class, 'removeAuxiliary'])->name('tables.remove-auxiliary');
            Route::post('elements', [FloorController::class, 'storeElement'])->name('elements.store');
            Route::patch('elements/{element}', [FloorController::class, 'updateElement'])->name('elements.update');
            Route::delete('elements/{element}', [FloorController::class, 'destroyElement'])->name('elements.destroy');
        });
    });
});

Route::middleware(['auth'])->prefix('asistente/api')->name('assistant.')->group(function () {
    Route::get('conversaciones', [AssistantController::class, 'index'])->name('index');
    Route::get('conversaciones/{conversation}', [AssistantController::class, 'show'])->name('show');
    Route::patch('conversaciones/{conversation}', [AssistantController::class, 'update'])->name('update');
    Route::delete('conversaciones/{conversation}', [AssistantController::class, 'destroy'])->name('destroy');
    Route::get('manual', [AssistantController::class, 'manual'])->name('manual');
    Route::post('chat', [AssistantController::class, 'chat'])->middleware('throttle:20,1')->name('chat');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('panel', DashboardController::class)->name('dashboard');

    Route::middleware(['admin'])->prefix('gestion')->name('admin.')->group(function () {
        Route::get('/', fn () => redirect()->route('dashboard'))->name('index');
        Route::get('asistente', [AssistantController::class, 'page'])->name('assistant');

        Route::get('usuarios', [AdminUserController::class, 'index'])->name('users');
        Route::post('usuarios', [AdminUserController::class, 'store'])->name('users.store');
        Route::patch('usuarios/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::patch('usuarios/{user}/password', [AdminUserController::class, 'resetPassword'])->name('users.password');
        Route::patch('usuarios/{user}/admin', [AdminUserController::class, 'updateAdmin'])->name('users.admin');
        Route::patch('usuarios/{user}/pin', [AdminUserController::class, 'pin'])->name('users.pin');
        Route::post('usuarios/{user}/qr', [AdminUserController::class, 'qr'])->name('users.qr');
        Route::patch('usuarios/{user}/activo', [AdminUserController::class, 'toggleActive'])->name('users.active');
        Route::delete('usuarios/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

        Route::get('dispositivos', [DeviceController::class, 'index'])->name('devices');
        Route::patch('dispositivos/{device}', [DeviceController::class, 'update'])->name('devices.update');
        Route::delete('dispositivos/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

        Route::get('zonas', [ZoneController::class, 'index'])->name('zones');
        Route::post('zonas', [ZoneController::class, 'store'])->name('zones.store');
        Route::post('zonas/orden', [ZoneController::class, 'reorder'])->name('zones.reorder');
        Route::patch('zonas/{zone}', [ZoneController::class, 'update'])->name('zones.update');
        Route::delete('zonas/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');

        Route::get('carta', [CatalogController::class, 'index'])->name('catalog');
        Route::post('carta/categorias', [CatalogController::class, 'storeCategory'])->name('categories.store');
        Route::post('carta/categorias/orden', [CatalogController::class, 'reorderCategories'])->name('categories.reorder');
        Route::patch('carta/categorias/{category}', [CatalogController::class, 'updateCategory'])->name('categories.update');
        Route::delete('carta/categorias/{category}', [CatalogController::class, 'destroyCategory'])->name('categories.destroy');
        Route::post('carta/productos', [CatalogController::class, 'storeProduct'])->name('products.store');
        Route::post('carta/productos/orden', [CatalogController::class, 'reorderProducts'])->name('products.reorder');
        Route::post('carta/productos/{product}', [CatalogController::class, 'updateProduct'])->name('products.update');
        Route::post('carta/productos/{product}/duplicar', [CatalogController::class, 'duplicateProduct'])->name('products.duplicate');
        Route::delete('carta/productos/{product}', [CatalogController::class, 'destroyProduct'])->name('products.destroy');
        Route::post('carta/modificadores', [CatalogController::class, 'storeModifierGroup'])->name('modifier-groups.store');
        Route::patch('carta/modificadores/{group}', [CatalogController::class, 'updateModifierGroup'])->name('modifier-groups.update');
        Route::delete('carta/modificadores/{group}', [CatalogController::class, 'destroyModifierGroup'])->name('modifier-groups.destroy');

        Route::get('menus', [SetMenuController::class, 'index'])->name('set-menus');
        Route::post('menus', [SetMenuController::class, 'store'])->name('set-menus.store');
        Route::patch('menus/{menu}', [SetMenuController::class, 'update'])->name('set-menus.update');
        Route::post('menus/{menu}/duplicar', [SetMenuController::class, 'duplicate'])->name('set-menus.duplicate');
        Route::delete('menus/{menu}', [SetMenuController::class, 'destroy'])->name('set-menus.destroy');

        Route::get('impresion', [PrintingController::class, 'index'])->name('printing');
        Route::get('impresion/registro', [PrintingController::class, 'log'])->name('printing.log');
        Route::post('impresion/destinos', [PrintingController::class, 'storeDestination'])->name('destinations.store');
        Route::patch('impresion/destinos/{destination}', [PrintingController::class, 'updateDestination'])->name('destinations.update');
        Route::delete('impresion/destinos/{destination}', [PrintingController::class, 'destroyDestination'])->name('destinations.destroy');
        Route::post('impresion/impresoras', [PrintingController::class, 'storePrinter'])->name('printers.store');
        Route::patch('impresion/impresoras/{printer}', [PrintingController::class, 'updatePrinter'])->name('printers.update');
        Route::delete('impresion/impresoras/{printer}', [PrintingController::class, 'destroyPrinter'])->name('printers.destroy');
        Route::post('impresion/impresoras/{printer}/prueba', [PrintingController::class, 'test'])->name('printers.test');

        Route::get('tiquets', [TicketController::class, 'index'])->name('tickets');
        Route::get('tiquets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::get('tiquets/{ticket}/pdf', [TicketController::class, 'pdf'])->name('tickets.pdf');
        Route::post('tiquets/{ticket}/factura', [TicketController::class, 'issueInvoice'])->name('tickets.invoice');
        Route::get('facturas', [TicketController::class, 'invoices'])->name('invoices');
        Route::get('facturas/{invoice}/pdf', [TicketController::class, 'invoicePdf'])->name('invoices.pdf');
        Route::post('facturas/{invoice}/reenviar', [TicketController::class, 'resendInvoice'])->name('invoices.resend');
        Route::get('cierres', [TicketController::class, 'cashSessions'])->name('cash-sessions');
        Route::get('cierres/{session}/pdf', [TicketController::class, 'zPdf'])->name('cash-sessions.pdf');

        Route::get('registro-horario', [TimeTrackingController::class, 'index'])->name('time');
        Route::post('registro-horario/{user}/correcciones', [TimeTrackingController::class, 'correct'])->name('time.correct');
        Route::get('registro-horario/exportar', [TimeTrackingController::class, 'export'])->name('time.export');

        Route::get('turnos', [ShiftController::class, 'index'])->name('shifts');
        Route::post('turnos', [ShiftController::class, 'store'])->name('shifts.store');
        Route::post('turnos/copiar-semana', [ShiftController::class, 'copyPreviousWeek'])->name('shifts.copy-week');
        Route::patch('turnos/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
        Route::delete('turnos/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');
        Route::post('turnos/plantillas', [ShiftController::class, 'storeTemplate'])->name('shift-templates.store');
        Route::patch('turnos/plantillas/{template}', [ShiftController::class, 'updateTemplate'])->name('shift-templates.update');
        Route::delete('turnos/plantillas/{template}', [ShiftController::class, 'destroyTemplate'])->name('shift-templates.destroy');

        Route::get('proveedores', [SupplierInvoiceController::class, 'index'])->name('suppliers');
        Route::post('proveedores', [SupplierInvoiceController::class, 'store'])->name('suppliers.store');
        Route::patch('proveedores/{invoice}', [SupplierInvoiceController::class, 'update'])->name('suppliers.update');
        Route::delete('proveedores/{invoice}', [SupplierInvoiceController::class, 'destroy'])->name('suppliers.destroy');
        Route::get('proveedores/{invoice}/archivo', [SupplierInvoiceController::class, 'file'])->name('suppliers.file');

        Route::get('ajustes', [SettingsController::class, 'edit'])->name('settings');
        Route::post('ajustes', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('ajustes/clave-fichaje', [SettingsController::class, 'regenerateClockSecret'])->name('settings.clock-secret');

        Route::get('auditoria', [AuditController::class, 'index'])->name('audit');

        Route::get('api', [ApiTokenController::class, 'index'])->name('api-tokens');
        Route::post('api', [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('api/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
    });
});

require __DIR__.'/settings.php';
