<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


//create a schedule to run the SendSMS command every 5 seconds
// Artisan::command('schedule:send-sms', function () {
//     $this->call('send:sms');
// })->purpose('Schedule to send SMS every 5 seconds');

Schedule::command('send:sms')->everyFiveSeconds()->withoutOverlapping();
Schedule::command('process:survey-progress')->everyFiveSeconds()->withoutOverlapping();
Schedule::command('surveys:dispatch-due')->everyFiveSeconds()->withoutOverlapping();
Schedule::command('dispatch:whatsapp-text')->everyFiveSeconds()->withoutOverlapping();


// Loan interest accrual - run daily at 9 AM
Schedule::command('loans:accrue-interest')->dailyAt('09:00');

