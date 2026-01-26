<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Member;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Console\Command;

class EstimateSurveyCost extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'survey:estimate-cost 
                            {survey_id : The ID of the survey}
                            {--groups= : Comma-separated list of group IDs (e.g., 1,2,3)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Estimate the number of messages and credits required to send a survey to one or more groups';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $surveyId = $this->argument('survey_id');
        $groupsInput = $this->option('groups');

        // Validate survey exists
        $survey = Survey::find($surveyId);
        if (!$survey) {
            $this->error("Survey with ID {$surveyId} not found.");
            return 1;
        }

        // Parse group IDs
        if (!$groupsInput) {
            $this->error("Please provide group IDs using --groups option (e.g., --groups=1,2,3)");
            return 1;
        }

        $groupIds = array_map('trim', explode(',', $groupsInput));
        $groupIds = array_filter($groupIds, 'is_numeric');
        
        if (empty($groupIds)) {
            $this->error("Invalid group IDs provided. Please use comma-separated numeric IDs.");
            return 1;
        }

        $this->info("📊 Survey Cost Estimation");
        $this->info("Survey: {$survey->title} (ID: {$surveyId})");
        $this->newLine();

        // Get all questions for the survey (ordered by position)
        // Only count questions that have swahili_question_id set (either with alternative OR marked as "no alternative")
        // Exclude questions where swahili_question_id IS NULL (Kiswahili questions or unconfigured)
        // Since members receive either English OR Kiswahili version, not both
        $allQuestions = $survey->questions()->get();
        
        // Filter to only English questions (swahili_question_id IS NOT NULL)
        // This includes:
        // 1. Questions with a real Kiswahili alternative (swahili_question_id != question.id)
        // 2. Questions marked as "no alternative" (swahili_question_id == question.id)
        // Excludes: Questions where swahili_question_id IS NULL (Kiswahili questions)
        $questions = $allQuestions->filter(function ($question) {
            return $question->swahili_question_id !== null;
        });
        
        if ($questions->isEmpty()) {
            $this->error("Survey has no English questions configured (swahili_question_id is null for all questions).");
            $this->warn("Note: Only questions with swahili_question_id set (with alternative or marked as 'no alternative') are counted.");
            return 1;
        }

        $totalQuestions = $allQuestions->count();
        $questionsCounted = $questions->count();
        $this->info("Found {$totalQuestions} total question(s) in survey");
        $this->info("Counting {$questionsCounted} question(s) with swahili_question_id set (includes alternatives and 'no alternative' markers)");
        $this->newLine();

        // Get a sample member for message formatting (use first active member from first group)
        $sampleMember = null;
        $firstGroup = Group::find($groupIds[0]);
        if ($firstGroup) {
            $sampleMember = $firstGroup->members()->where('is_active', true)->first();
            
            // If member doesn't have group_id set, set it from the first group
            if ($sampleMember && !$sampleMember->group_id) {
                $sampleMember->group_id = $firstGroup->id;
            }
        }

        if (!$sampleMember) {
            // Create a dummy member for estimation with group_id set
            $sampleMember = new Member([
                'name' => 'Sample Member',
                'phone' => '0712345678',
                'group_id' => $firstGroup ? $firstGroup->id : 1, // Default to first group or 1
            ]);
        }
        
        // Ensure the member has the group relationship set
        if ($sampleMember && $firstGroup) {
            $sampleMember->setRelation('group', $firstGroup);
        } elseif ($sampleMember && $sampleMember->group_id) {
            // Load the group if group_id is set
            $sampleMember->load('group');
        }

        // Calculate message length and credits for each question
        $questionDetails = [];
        $totalQuestionCredits = 0;

        foreach ($questions as $question) {
            $message = formartQuestion($question, $sampleMember, $survey, false);
            $messageLength = mb_strlen($message);
            $credits = \App\Models\SMSInbox::calculateCredits($message);
            
            $questionDetails[] = [
                'question_id' => $question->id,
                'question_text' => substr($question->question, 0, 50) . (strlen($question->question) > 50 ? '...' : ''),
                'message_length' => $messageLength,
                'credits' => $credits,
            ];
            
            $totalQuestionCredits += $credits;
        }

        // Display question breakdown
        $this->info("Question Breakdown:");
        $this->table(
            ['Question ID', 'Question (Preview)', 'Message Length', 'Credits'],
            collect($questionDetails)->map(function ($q) {
                return [
                    $q['question_id'],
                    $q['question_text'],
                    $q['message_length'] . ' chars',
                    $q['credits'],
                ];
            })->toArray()
        );
        $this->newLine();

        // Process each group
        $groupResults = [];
        $grandTotalMembers = 0;
        $grandTotalMessages = 0;
        $grandTotalCredits = 0;

        foreach ($groupIds as $groupId) {
            $group = Group::find($groupId);
            if (!$group) {
                $this->warn("Group with ID {$groupId} not found. Skipping.");
                continue;
            }

            // Get all active members in this group (no stage or order filtering)
            $members = $group->members()->where('is_active', true)->get();
            
            $memberCount = $members->count();
            
            // Calculate totals for this group
            $messagesPerMember = $questions->count(); // One message per question
            $totalMessages = $memberCount * $messagesPerMember;
            $totalCredits = $memberCount * $totalQuestionCredits;

            $groupResults[] = [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'members' => $memberCount,
                'messages' => $totalMessages,
                'credits' => $totalCredits,
            ];

            $grandTotalMembers += $memberCount;
            $grandTotalMessages += $totalMessages;
            $grandTotalCredits += $totalCredits;
        }

        // Display group breakdown
        $this->info("Group Breakdown:");
        $this->table(
            ['Group ID', 'Group Name', 'Active Members', 'Total Messages', 'Total Credits'],
            collect($groupResults)->map(function ($g) {
                return [
                    $g['group_id'],
                    $g['group_name'],
                    number_format($g['members']),
                    number_format($g['messages']),
                    number_format($g['credits']),
                ];
            })->toArray()
        );
        $this->newLine();

        // Display summary
        $this->info("📈 Summary:");
        $this->line("   Total Groups: " . count($groupResults));
        $this->line("   Total Active Members: " . number_format($grandTotalMembers));
        $this->line("   Total Messages (if all complete): " . number_format($grandTotalMessages));
        $this->line("   Total Credits Required (maximum): " . number_format($grandTotalCredits));
        $this->newLine();
        $this->comment("   Note: This is a maximum estimate assuming all active members complete the survey.");
        $this->comment("   Initial send will only use " . number_format($grandTotalMembers) . " messages (first question).");
        $this->comment("   Additional messages are sent as members respond and progress through the survey.");
        $this->newLine();

        // Check current credit balance
        $currentBalance = \App\Models\SmsCredit::getBalance();
        $this->info("💰 Current Credit Balance: " . number_format($currentBalance));
        
        if ($grandTotalCredits > $currentBalance) {
            $shortfall = $grandTotalCredits - $currentBalance;
            $this->error("   ⚠️  Insufficient credits! Shortfall: " . number_format($shortfall));
        } else {
            $remaining = $currentBalance - $grandTotalCredits;
            $this->info("   ✅ Sufficient credits. Remaining after send: " . number_format($remaining));
        }

        return 0;
    }
}
