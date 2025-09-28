<?php

use App\Http\Controllers\SurveyFlowController;
use App\Http\Controllers\WhatsAppController;
use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/surveys/{survey}/questions', [SurveyFlowController::class, 'getQuestions']);

Route::get('/surveys/{survey}/flow', [SurveyFlowController::class, 'getFlow']);

Route::post('/surveys/{survey}/flow', [SurveyFlowController::class, 'saveFlow']);


Route::prefix('v1')->name('api.')->group(function () {
    Route::controller(WhatsAppController::class)->group(function(){
        Route::any('webhook', 'handleWebhook'); 
    });
});