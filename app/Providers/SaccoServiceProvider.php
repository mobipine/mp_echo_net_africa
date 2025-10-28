<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\{
    TransactionService,
    BalanceCalculationService,
    SavingsService,
    SubscriptionPaymentService,
    FeePaymentService,
    FeeAccrualService
};

class SaccoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(TransactionService::class);
        $this->app->singleton(BalanceCalculationService::class);
        $this->app->singleton(SavingsService::class);
        $this->app->singleton(SubscriptionPaymentService::class);
        $this->app->singleton(FeePaymentService::class);
        $this->app->singleton(FeeAccrualService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
