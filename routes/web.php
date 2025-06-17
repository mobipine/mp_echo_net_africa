<?php

use App\Http\Controllers\WebHookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
    //redirect to the /admin route
    return redirect('/admin');
});

Route::post('/webhook', [WebHookController::class, 'handleWebhook']);//route to receive responses from short code SMS Service
