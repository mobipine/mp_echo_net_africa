<?php

use App\Http\Controllers\SmsExportsController;
use App\Http\Controllers\ResponseExportsController;
use App\Http\Controllers\SurveyExportsController;
use App\Http\Controllers\UssdWebHookController;
use App\Http\Controllers\WebHookController;
use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
    //redirect to the /admin route
    return redirect('/admin');
});

Route::any('/webhook', [WebHookController::class, 'handleWebhook']);//route to receive responses from short code SMS Service
Route::any('/ussd-webhook', [UssdWebHookController::class, 'handleUssdWebhook']);//route to receive responses from USSD Service

Route::get('/export-sms/{scope}', [SmsExportsController::class, 'index'])
    ->name('sms.export');

Route::any('/export-surveys/{scope}', [SurveyExportsController::class, 'export'])
    ->name('survey.export');


Route::get('/export-responses', [ResponseExportsController::class, 'export'])->name('response.export');

Route::get('/get-next-qtn', function () {
    // return view('get-next-qtn');

    //define params
    $survey_id = 3;
    $member_id = 2;
    $response = "China";//the response to the current question (this will help us determine the next question in case of branching)
    $current_question_id = 11;//if not null, get the next question after this one
    // $current_question_id = null;//if null, get the first question

    //get the flow data from the survey
    $survey = \App\Models\Survey::find($survey_id);
    $flowData = $survey->flow_data;
    // dd($flowData);

    $elements = $flowData['elements'];
    $edges = $flowData['edges'];



    if($current_question_id == null) {

        //get the start element
        //this will be the element with a label of "Start"
        $startElement = null;
        foreach ($elements as $element) {
            if ($element['label'] == 'Start') {
                $startElement = $element;
                break;
            }
        }

        //get the first question element
        //this will be the element that is connected to the start element (the start element can only have one connection)
        //go to the edges and find the edge that has the start element as the source
        $startElementId = $startElement['id'];
        $startingEdge = null;
        foreach ($edges as $edge) {
            if ($edge['source'] == $startElementId) {
                $startingEdge = $edge;
                break;
            }
        }

        //get the target of the starting edge
        $firstQuestionElementId = $startingEdge['target'];
        $firstQuestionElement = null;
        foreach ($elements as $element) {
            if ($element['id'] == $firstQuestionElementId) {
                $firstQuestionElement = $element;
                break;
            }
        }

        //get the question_id
        $next_question_id = $firstQuestionElement['data']['questionId'];


    } else {
        //get the current question element
        $currentQuestionElement = null;
        foreach ($elements as $element) {
            if ($element['data']['questionId'] == $current_question_id) {
                $currentQuestionElement = $element;
                break;
            }
        }

        // dd($currentQuestionElement);

        //get all edges that have the current question element as the source
        $outgoingEdges = [];
        foreach ($edges as $edge) {
            if ($edge['source'] == $currentQuestionElement['id']) {
                $outgoingEdges[] = $edge;
            }
        }

        //first check the answer strictness
        $answer_strictness = $currentQuestionElement['data']['answerStrictness'];
        if($answer_strictness == "Open-Ended") {
            // take the first array element of the outgoing edges and get its target
            $nextQuestionElementId = $outgoingEdges[0]['target'];
            $nextQuestionElement = null;
            foreach ($elements as $element) {
                if ($element['id'] == $nextQuestionElementId) {
                    $nextQuestionElement = $element;
                    break;
                }
            }
            //get the question_id
            $next_question_id = $nextQuestionElement['data']['questionId'];


        } else {
            //get the possible answers and the flows they lead to
            $possibleAnswers = $currentQuestionElement['data']['possibleAnswers'];

            //check if the response matches any of the possible answers
            $matchedFlow = null;
            foreach ($possibleAnswers as $answer) {
                if (strcasecmp($answer['answer'], $response) == 0) {
                    $matchedFlow = $answer['linkedFlow'];
                    break;
                }
            }

            // dd($matchedFlow, $possibleAnswers, $response, $outgoingEdges);

            if ($matchedFlow) {
                //get the edge that matches the linked flow
                $matchedEdge = null;
                foreach ($outgoingEdges as $edge) {
                    if ($edge['id'] == $matchedFlow) {
                        $matchedEdge = $edge;
                        break;
                    }
                }

                // dd($matchedEdge, $outgoingEdges);

                //get the target of the matched edge
                $nextQuestionElementId = $matchedEdge['target'];
                $nextQuestionElement = null;
                foreach ($elements as $element) {
                    if ($element['id'] == $nextQuestionElementId) {
                        $nextQuestionElement = $element;
                        break;
                    }
                }

                //get the question_id
                $next_question_id = $nextQuestionElement['data']['questionId'];

            } else {
                //if no match, check if there is a violation response
                $violationResponse = $currentQuestionElement['data']['violationResponse'];
                if ($violationResponse) {
                    //send the violation response
                    return [
                        'status' => 'violation',
                        'message' => $violationResponse
                    ];
                } else {
                    return [
                        'status' => 'error',
                        'message' => 'No matching answer found and no violation response set.'
                    ];
                }
            }

        }




    }

    if($next_question_id) {
        $next_question = SurveyQuestion::find($next_question_id);
        return $next_question;
    } else {
        return [
            'status' => 'completed',
            'message' => 'No more questions in the survey.'
        ];
    }

    // dd($next_question_id, $next_question, $next_question->question );



});
