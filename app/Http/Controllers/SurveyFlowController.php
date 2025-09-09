<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use Illuminate\Http\Request;

class SurveyFlowController extends Controller
{
    public function getQuestions($surveyId)
    {
        $survey = Survey::with('questions')->findOrFail($surveyId);
        return response()->json($survey->questions);
    }

    public function getFlow($surveyId)
    {
        $survey = Survey::findOrFail($surveyId);
        return response()->json($survey->flow_data);
    }

    public function saveFlow(Request $request, $surveyId)
    {
        $survey = Survey::findOrFail($surveyId);

        //TODO: VALIDATE THE REQUEST DATA


        $survey->flow_data = $request->all();
        $survey->save();

        return response()->json(['message' => 'Flow saved successfully']);
    }
}
