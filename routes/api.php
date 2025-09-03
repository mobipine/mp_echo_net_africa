<?php

use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/surveys/{survey}/questions', function ($surveyId) {
    $survey = Survey::with('questions')->findOrFail($surveyId);
    // dd($survey->questions);
    return response()->json($survey->questions);
});

Route::get('/surveys/{survey}/flow', function ($surveyId) {
    $survey = Survey::findOrFail($surveyId);
    return response()->json($survey->flow_data);
});

Route::post('/surveys/{survey}/flow', function (Request $request, $surveyId) {
    $survey = Survey::findOrFail($surveyId);
    $survey->flow_data = $request->all();
    $survey->save();
    
    return response()->json(['message' => 'Flow saved successfully']);
});