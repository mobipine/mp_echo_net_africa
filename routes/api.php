<?php

use App\Http\Controllers\SurveyFlowController;
use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/surveys/{survey}/questions', [SurveyFlowController::class, 'getQuestions']);

Route::get('/surveys/{survey}/flow', [SurveyFlowController::class, 'getFlow']);

Route::post('/surveys/{survey}/flow', [SurveyFlowController::class, 'saveFlow']);