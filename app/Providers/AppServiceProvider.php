<?php

namespace App\Providers;

use App\Services\Printing\PrinterDriver;
use App\Services\Printing\PrintService;
use App\Services\Printing\SimulatedPrinterDriver;
use App\Services\Sync\Handlers\CashierOperations;
use App\Services\Sync\Handlers\KitchenOperations;
use App\Services\Sync\Handlers\OrderOperations;
use App\Services\Sync\Handlers\ReservationOperations;
use App\Services\Sync\Handlers\TimeOperations;
use App\Services\Sync\OperationProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PrinterDriver::class, SimulatedPrinterDriver::class);

        $this->app->singleton(OperationProcessor::class, fn ($app) => new OperationProcessor([
            $app->make(OrderOperations::class),
            $app->make(KitchenOperations::class),
            $app->make(CashierOperations::class),
            $app->make(ReservationOperations::class),
            $app->make(TimeOperations::class),
        ], $app->make(PrintService::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
