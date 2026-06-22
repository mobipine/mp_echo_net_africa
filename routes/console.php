<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// SMS and Survey Processing Commands.
// Driven by a single scheduler process (cron `schedule:run` or `schedule:work`).
// withoutOverlapping() prevents a slow run from colliding with the next tick — it only
// takes effect through the scheduler, NOT when commands are invoked directly from cron.

// Outbound sender: light (only processes pending rows, returns immediately when empty) —
// run every 5s so the next question (queued synchronously by the inbound webhook) goes out
// almost immediately instead of waiting on a slow poll.
Schedule::command('dispatch:sms')
    ->everyFiveSeconds()
    ->withoutOverlapping();

// Advances members to their next question / sends reminders. Heavier (scans active
// progress), so a slightly slower cadence; withoutOverlapping throttles long runs.
Schedule::command('process:surveys-progress')
    ->everyThirtySeconds()
    ->withoutOverlapping();

Schedule::command('surveys:due-dispatch')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('send:whatsapp-text')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('sms:fetch-delivery')
    ->everyMinute()
    ->withoutOverlapping();

// Loan interest accrual - run daily at 9 AM
Schedule::command('loans:accrue-interest')->dailyAt('09:00');

