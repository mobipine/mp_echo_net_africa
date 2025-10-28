<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        if (config('app.env') == 'local') {
            URL::forceScheme('https'); // Force HTTPS
        }
        
        // Register observers
        \App\Models\Member::observe(\App\Observers\MemberObserver::class);
        \App\Models\Group::observe(\App\Observers\GroupObserver::class);
    }
}
