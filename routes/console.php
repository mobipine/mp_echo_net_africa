<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// SMS and Survey Processing Commands
// These run every 5 seconds with overlap protection to prevent duplicate message sends
Schedule::command('dispatch:sms')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();


Schedule::command('process:surveys-progress')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('surveys:due-dispatch')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('send:whatsapp-text')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sms:fetch-delivery')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();

// Loan interest accrual - run daily at 9 AM
Schedule::command('loans:accrue-interest')->dailyAt('09:00');

