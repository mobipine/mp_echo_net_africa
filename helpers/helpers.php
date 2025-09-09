<?php

//WE CREATED A FLOW BUILDER TABLE TO HANDLE THE NEXT QUESTION FLOW BASED ON THE ANSWERS
function getNextQuestion($survey_id, $response = null, $current_question_id = null)
{
    //get the flow data from the survey
    $survey = \App\Models\Survey::find($survey_id);
    $flowData = $survey->flow_data;
    // dd($flowData);

    if (!$flowData) {
        return [
            'status' => 'error',
            'message' => 'No flow data found for this survey.'
        ];
    }

    $elements = $flowData['elements'];
    $edges = $flowData['edges'];

    if(!$elements || !is_array($elements) || count($elements) == 0) {
        return [
            'status' => 'error',
            'message' => 'No elements found in the flow data.'
        ];
    }

    if ($current_question_id == null) {

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
        if ($answer_strictness == "Open-Ended") {
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
    
    // dd($next_question_id, $next_question, $next_question->question );
    if ($next_question_id) {
        $next_question = \App\Models\SurveyQuestion::find($next_question_id);
        return $next_question;
    }

}