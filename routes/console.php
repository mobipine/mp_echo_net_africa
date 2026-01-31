<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// SMS and Survey Processing Commands
// Run every minute (when cron runs schedule:run); withoutOverlapping prevents duplicate sends
Schedule::command('dispatch:sms')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('process:surveys-progress')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('surveys:due-dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('send:whatsapp-text')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sms:fetch-delivery')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Loan interest accrual - run daily at 9 AM
Schedule::command('loans:accrue-interest')->dailyAt('09:00');

