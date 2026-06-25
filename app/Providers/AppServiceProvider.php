<?php

namespace App\Providers;

use App\Contracts\SmsTransport;
use App\Services\BongaSMS;
use App\Services\FakeSmsTransport;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsTransport::class, function ($app) {
            return match (config('sms.driver')) {
                'fake' => $app->make(FakeSmsTransport::class),
                default => $app->make(BongaSMS::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        $appUrl = (string) config('app.url', '');
        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
        
        // Register observers
        \App\Models\Member::observe(\App\Observers\MemberObserver::class);
        \App\Models\Group::observe(\App\Observers\GroupObserver::class);
    }
}
