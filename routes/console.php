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

Schedule::call(function () {
    Artisan::call('send:sms');
})->name('send_sms')->everyFiveSeconds()->withoutOverlapping();

Schedule::command('surveys:dispatch-due')->everyMinute();
Schedule::command('surveys:process-progress')->everyMinute();

