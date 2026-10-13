<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setBusinessTranslation('Taja biashara unayojishugulishs nayo?');
    }

    public function down(): void
    {
        $this->setBusinessTranslation(null);
    }

    private function setBusinessTranslation(?string $translationPrompt): void
    {
        $surveyId = DB::table('surveys')
            ->whereRaw('LOWER(TRIM(title)) = ?', ['finance'])
            ->orderBy('id')
            ->value('id');

        if (! $surveyId) {
            return;
        }

        $englishId = DB::table('survey_question_survey as pivot')
            ->join('survey_questions as question', 'question.id', '=', 'pivot.survey_question_id')
            ->where('pivot.survey_id', $surveyId)
            ->whereRaw('TRIM(question.question) = ?', ['Please specify  the business you are engaged in'])
            ->value('question.id');

        $translationId = $translationPrompt
            ? DB::table('survey_question_survey as pivot')
                ->join('survey_questions as question', 'question.id', '=', 'pivot.survey_question_id')
                ->where('pivot.survey_id', $surveyId)
                ->whereRaw('TRIM(question.question) = ?', [$translationPrompt])
                ->value('question.id')
            : (DB::table('survey_questions')->where('id', 27)->value('id') ?: null);

        if ($englishId && $translationId) {
            DB::table('survey_questions')
                ->where('id', $englishId)
                ->update(['swahili_question_id' => $translationId]);
        }
    }
};
