<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetNextQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_next_question_when_linked_flow_matches_an_edge_id(): void
    {
        [$survey, $firstQuestion, $secondQuestion] = $this->createSurveyWithBasicQuestions();

        $survey->update([
            'flow_data' => [
                'elements' => [
                    ['id' => 'start', 'label' => 'Start', 'type' => 'input', 'data' => []],
                    [
                        'id' => 'q1-node',
                        'label' => $firstQuestion->question,
                        'type' => 'default',
                        'data' => [
                            'questionId' => $firstQuestion->id,
                            'answerStrictness' => 'Multiple Choice',
                            'possibleAnswers' => [
                                ['answer' => 'Yes', 'linkedFlow' => 'q1-node-q2-node'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'q2-node',
                        'label' => $secondQuestion->question,
                        'type' => 'default',
                        'data' => [
                            'questionId' => $secondQuestion->id,
                            'answerStrictness' => 'Open-Ended',
                        ],
                    ],
                ],
                'edges' => [
                    ['id' => 'start-q1-node', 'source' => 'start', 'target' => 'q1-node'],
                    ['id' => 'q1-node-q2-node', 'source' => 'q1-node', 'target' => 'q2-node'],
                ],
            ],
        ]);

        $nextQuestion = getNextQuestion($survey->id, 'Yes', $firstQuestion->id);

        $this->assertInstanceOf(SurveyQuestion::class, $nextQuestion);
        $this->assertTrue($nextQuestion->is($secondQuestion));
    }

    public function test_it_accepts_a_target_node_id_as_a_backward_compatible_linked_flow_value(): void
    {
        [$survey, $firstQuestion, $secondQuestion] = $this->createSurveyWithBasicQuestions();

        $survey->update([
            'flow_data' => [
                'elements' => [
                    ['id' => 'start', 'label' => 'Start', 'type' => 'input', 'data' => []],
                    [
                        'id' => 'q1-node',
                        'label' => $firstQuestion->question,
                        'type' => 'default',
                        'data' => [
                            'questionId' => $firstQuestion->id,
                            'answerStrictness' => 'Multiple Choice',
                            'possibleAnswers' => [
                                ['answer' => 'Yes', 'linkedFlow' => 'q2-node'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'q2-node',
                        'label' => $secondQuestion->question,
                        'type' => 'default',
                        'data' => [
                            'questionId' => $secondQuestion->id,
                            'answerStrictness' => 'Open-Ended',
                        ],
                    ],
                ],
                'edges' => [
                    ['id' => 'start-q1-node', 'source' => 'start', 'target' => 'q1-node'],
                    ['id' => 'q1-node-q2-node', 'source' => 'q1-node', 'target' => 'q2-node'],
                ],
            ],
        ]);

        $nextQuestion = getNextQuestion($survey->id, 'Yes', $firstQuestion->id);

        $this->assertInstanceOf(SurveyQuestion::class, $nextQuestion);
        $this->assertTrue($nextQuestion->is($secondQuestion));
    }

    public function test_it_returns_null_when_a_branch_leads_to_an_end_node(): void
    {
        [$survey, $firstQuestion] = $this->createSurveyWithBasicQuestions();

        $survey->update([
            'flow_data' => [
                'elements' => [
                    ['id' => 'start', 'label' => 'Start', 'type' => 'input', 'data' => []],
                    [
                        'id' => 'q1-node',
                        'label' => $firstQuestion->question,
                        'type' => 'default',
                        'data' => [
                            'questionId' => $firstQuestion->id,
                            'answerStrictness' => 'Multiple Choice',
                            'possibleAnswers' => [
                                ['answer' => 'Yes', 'linkedFlow' => 'q1-node-end-node'],
                            ],
                        ],
                    ],
                    ['id' => 'end-node', 'label' => 'End', 'type' => 'output', 'data' => []],
                ],
                'edges' => [
                    ['id' => 'start-q1-node', 'source' => 'start', 'target' => 'q1-node'],
                    ['id' => 'q1-node-end-node', 'source' => 'q1-node', 'target' => 'end-node'],
                ],
            ],
        ]);

        $this->assertNull(getNextQuestion($survey->id, 'Yes', $firstQuestion->id));
    }

    public function test_it_returns_a_clear_error_when_the_matched_flow_no_longer_exists(): void
    {
        [$survey, $firstQuestion] = $this->createSurveyWithBasicQuestions();

        $survey->update([
            'flow_data' => [
                'elements' => [
                    ['id' => 'start', 'label' => 'Start', 'type' => 'input', 'data' => []],
                    [
                        'id' => 'q1-node',
                        'label' => $firstQuestion->question,
                        'type' => 'default',
                        'data' => [
                            'questionId' => $firstQuestion->id,
                            'answerStrictness' => 'Multiple Choice',
                            'possibleAnswers' => [
                                ['answer' => 'Yes', 'linkedFlow' => 'missing-edge'],
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    ['id' => 'start-q1-node', 'source' => 'start', 'target' => 'q1-node'],
                ],
            ],
        ]);

        $nextQuestion = getNextQuestion($survey->id, 'Yes', $firstQuestion->id);

        $this->assertIsArray($nextQuestion);
        $this->assertSame('error', $nextQuestion['status']);
        $this->assertStringContainsString('missing-edge', $nextQuestion['message']);
    }

    private function createSurveyWithBasicQuestions(): array
    {
        $survey = Survey::create([
            'title' => 'Finance Survey',
            'description' => 'Test flow',
            'trigger_word' => 'finance',
            'final_response' => 'Thanks',
            'status' => 'Active',
            'continue_confirmation_interval' => 1,
            'continue_confirmation_interval_unit' => 'minutes',
        ]);

        $firstQuestion = SurveyQuestion::create([
            'question' => 'Did you receive a loan?',
            'purpose' => 'regular',
            'answer_data_type' => 'Alphanumeric',
            'answer_strictness' => 'Multiple Choice',
            'possible_answers' => [
                ['answer' => 'Yes'],
                ['answer' => 'No'],
            ],
        ]);

        $secondQuestion = SurveyQuestion::create([
            'question' => 'How much did you receive?',
            'purpose' => 'regular',
            'answer_data_type' => 'Strictly Number',
            'answer_strictness' => 'Open-Ended',
        ]);

        return [$survey, $firstQuestion, $secondQuestion];
    }
}
