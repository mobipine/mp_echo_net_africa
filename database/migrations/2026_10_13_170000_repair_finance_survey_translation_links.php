<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reconnect the active Finance survey's Kiswahili branch to its English questions.
     * The survey had duplicate Kiswahili questions in its flow, while English question
     * records still pointed to stale question IDs from an older survey version.
     */
    public function up(): void
    {
        $surveyId = DB::table('surveys')
            ->whereRaw('LOWER(TRIM(title)) = ?', ['finance'])
            ->orderBy('id')
            ->value('id');

        if (! $surveyId) {
            return;
        }

        foreach ($this->questionPairs() as $englishQuestion => $swahiliQuestion) {
            $englishId = $this->questionId((int) $surveyId, $englishQuestion);
            $swahiliId = $this->questionId((int) $surveyId, $swahiliQuestion);

            if (! $englishId || ! $swahiliId) {
                continue;
            }

            DB::table('survey_questions')
                ->where('id', $englishId)
                ->update(['swahili_question_id' => $swahiliId]);
        }
    }

    public function down(): void
    {
        $surveyId = DB::table('surveys')
            ->whereRaw('LOWER(TRIM(title)) = ?', ['finance'])
            ->orderBy('id')
            ->value('id');

        if (! $surveyId) {
            return;
        }

        // Restore the previous stale links if those legacy question records still exist.
        foreach ($this->legacyLinks() as $englishQuestion => $legacyQuestionId) {
            $englishId = $this->questionId((int) $surveyId, $englishQuestion);
            if (! $englishId) {
                continue;
            }

            $legacyId = DB::table('survey_questions')->where('id', $legacyQuestionId)->value('id');
            DB::table('survey_questions')
                ->where('id', $englishId)
                ->update(['swahili_question_id' => $legacyId]);
        }
    }

    private function questionId(int $surveyId, string $text): ?int
    {
        $id = DB::table('survey_question_survey as pivot')
            ->join('survey_questions as question', 'question.id', '=', 'pivot.survey_question_id')
            ->where('pivot.survey_id', $surveyId)
            ->whereRaw('TRIM(question.question) = ?', [trim($text)])
            ->value('question.id');

        return $id ? (int) $id : null;
    }

    private function questionPairs(): array
    {
        return [
            'Did you apply for a YISA RLF loan?' => 'Uliomba mkopo wa YISA RLF?',
            'How much (KES) did you apply for?' => 'Uliomba pesa ngapi?',
            'Have you received the YISA RLF?' => 'Je, umepokea mkopo wa YISA RLF?',
            'How much KES did you receive?' => 'Ulipokea kiasi gani?',
            'When did you receive the money? (Month/Year)' => 'Ulipokea fedha lini?',
            'Hi {member}, ENAF/YISA Loaned you {loan_amount_received} in {loan_received_month}. What did you use the loan for?' => 'ENAF/YISA ilikukopesha KES {amount} mwezi wa {month}. Uliutumia mkopo huu kufanya nini?',
            'Please specify what you used the loan for' => 'Taja ulichokifanya na mkopo uliopokea',
            'What is preventing you from using the money?' => 'Tafadhali eleza ni nini kinakuzuia kutumia fedha ulizopokea.',
            'Which business are you engaged in?' => 'Unajishughulisha na biashara gani?',
            // The production English prompt contains two spaces after "specify".
            'Please specify  the business you are engaged in' => 'Taja biashara unayejishugulishs nayo?',
            'Which fish species are you selling?' => 'Unauza aina gani ya samaki?',
            'Please specify the fish species you are selling' => 'Taja aina ya samaki unayouza?',
            'In the past, how much capital did you input daily (stock, transport, rent, electricity, levy, etc.)?' => 'Hapo awali, ulikuwa unaingiza mtaji kiasi gani kila siku (hisa, usafiri, kodi, umeme, ushuru n.k.)?',
            'How much in daily sales (gross) does your business generate (KES)?' => 'Je, biashara yako inazalisha kiasi gani katika mauzo ya kila siku (KES)?',
        ];
    }

    private function legacyLinks(): array
    {
        return [
            'Did you apply for a YISA RLF loan?' => 14,
            'How much (KES) did you apply for?' => 15,
            'Have you received the YISA RLF?' => 16,
            'How much KES did you receive?' => 17,
            'When did you receive the money? (Month/Year)' => 18,
            'Hi {member}, ENAF/YISA Loaned you {loan_amount_received} in {loan_received_month}. What did you use the loan for?' => 19,
            'Please specify what you used the loan for' => 28,
            'What is preventing you from using the money?' => 20,
            'Which business are you engaged in?' => 21,
            'Please specify the business you are engaged in' => 27,
            'Which fish species are you selling?' => 23,
            'Please specify the fish species you are selling' => 22,
            'In the past, how much capital did you input daily (stock, transport, rent, electricity, levy, etc.)?' => 24,
            'How much in daily sales (gross) does your business generate (KES)?' => 25,
        ];
    }
};
