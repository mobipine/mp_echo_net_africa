<?php

use App\Http\Controllers\SurveyFlowController;
use App\Http\Controllers\UssdFlowController;
use App\Http\Controllers\UssdWebHookController;
use App\Http\Controllers\WhatsAppController;
use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/surveys/{survey}/questions', [SurveyFlowController::class, 'getQuestions']);

Route::get('/surveys/{survey}/flow', [SurveyFlowController::class, 'getFlow']);

Route::post('/surveys/{survey}/flow', [SurveyFlowController::class, 'saveFlow']);

// USSD Flow Management Routes
Route::prefix('ussd-flows')->group(function () {
    Route::get('/', [UssdFlowController::class, 'index']);
    Route::post('/', [UssdFlowController::class, 'store']);
    Route::get('/{id}', [UssdFlowController::class, 'show']);
    Route::put('/{id}', [UssdFlowController::class, 'update']);
    Route::delete('/{id}', [UssdFlowController::class, 'destroy']);
    Route::post('/{id}/activate', [UssdFlowController::class, 'activate']);
    Route::get('/{id}/flow', [UssdFlowController::class, 'getFlow']);
    Route::post('/{id}/flow', [UssdFlowController::class, 'saveFlow']);
});


Route::prefix('v1')->name('api.')->group(function () {
    Route::controller(WhatsAppController::class)->group(function(){
        Route::any('webhook', 'handleWebhook');
    });
});

// USSD Webhook - External service endpoint (no CSRF protection needed)
// NOTE: This route is at /api/ussd-webhook - update your USSD provider configuration
Route::any('/ussd-webhook', [UssdWebHookController::class, 'handleUssdWebhook']);
