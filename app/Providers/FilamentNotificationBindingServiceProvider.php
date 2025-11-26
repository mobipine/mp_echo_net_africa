<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Filament\Notifications\Auth\ResetPassword as FilamentResetPassword;

class FilamentNotificationBindingServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Bind Filament's ResetPassword to your custom class
        $this->app->bind(
            FilamentResetPassword::class,
            \App\Notifications\CustomResetPasswordNotification::class
        );
    }
}
