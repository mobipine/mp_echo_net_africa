<?php

namespace Database\Seeders;

use App\Models\SMSInbox;
use App\Models\SurveyResponse;
use App\Models\CreditTransaction;
use App\Models\SmsCredit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PopulateHistoricalCreditTransactionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates credit transactions for historical data:
     * 1. CLEARS all existing 'sms_sent' and 'sms_received' transactions (preserves 'load' transactions)
     * 2. Creates transactions for all sent SMS messages (sms_inboxes where status='sent')
     * 3. Creates transactions for all received survey responses (survey_responses table)
     * 4. RECALCULATES and updates SMS credit balance (Total Added - Total Subtracted)
     *
     * Safe to run multiple times - will clear and repopulate SMS transactions each time.
     */
    public function run(): void
    {
        $this->command->info('🔄 Starting to populate historical credit transactions...');
        $this->command->newLine();

        // Get current balance before we start
        $currentBalance = SmsCredit::getBalance();
        $this->command->info("📊 Current SMS Credit Balance: " . number_format($currentBalance));
        $this->command->newLine();

        // Clear existing SMS-related credit transactions
        $this->clearExistingSmsTransactions();

        // Track statistics
        $stats = [
            'sent_created' => 0,
            'sent_skipped' => 0,
            'received_created' => 0,
            'received_skipped' => 0,
        ];

        // Process sent messages
        $this->command->info('📤 Processing sent SMS messages...');
        $stats = array_merge($stats, $this->processSentMessages());
        $this->command->newLine();

        // Process received responses
        $this->command->info('📥 Processing received survey responses...');
        $stats = array_merge($stats, $this->processReceivedResponses());
        $this->command->newLine();

        // Recalculate and update SMS credit balance
        $newBalance = $this->recalculateCreditBalance();

        // Display summary
        $this->displaySummary($stats, $currentBalance, $newBalance);

        Log::info("PopulateHistoricalCreditTransactionsSeeder completed", array_merge($stats, [
            'old_balance' => $currentBalance,
            'new_balance' => $newBalance,
        ]));
    }

    /**
     * Recalculate SMS credit balance based on all transactions
     * Formula: Balance = Total Added - Total Subtracted
     */
    protected function recalculateCreditBalance(): int
    {
        $this->command->newLine();
        $this->command->info('💰 Recalculating SMS credit balance...');

        DB::beginTransaction();
        try {
            // Get total added (load transactions)
            $totalAdded = CreditTransaction::where('type', 'add')->sum('amount');

            // Get total subtracted (sms_sent and sms_received)
            $totalSubtracted = CreditTransaction::where('type', 'subtract')->sum('amount');

            // Calculate new balance
            $newBalance = $totalAdded - $totalSubtracted;

            $this->command->info("   • Total credits loaded: " . number_format($totalAdded));
            $this->command->info("   • Total credits used: " . number_format($totalSubtracted));
            $this->command->info("   • Calculated balance: " . number_format($newBalance));

            // Update sms_credits table
            $credit = SmsCredit::firstOrCreate([], ['balance' => 0]);
            $oldBalance = $credit->balance;
            $credit->update(['balance' => $newBalance]);

            $this->command->info("   ✅ Updated SMS credit balance from " . number_format($oldBalance) . " to " . number_format($newBalance));

            DB::commit();

            Log::info("Recalculated SMS credit balance: {$oldBalance} → {$newBalance}");

            return $newBalance;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error("   ❌ Failed to recalculate balance: " . $e->getMessage());
            Log::error("Failed to recalculate SMS credit balance: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Clear existing SMS-related credit transactions (sent and received)
     * Keeps 'load' transactions (manual credit loading)
     */
    protected function clearExistingSmsTransactions(): void
    {
        $this->command->warn('🗑️  Clearing existing SMS-related credit transactions...');

        // Count existing transactions before deletion
        $sentCount = CreditTransaction::where('transaction_type', 'sms_sent')->count();
        $receivedCount = CreditTransaction::where('transaction_type', 'sms_received')->count();
        $totalToDelete = $sentCount + $receivedCount;

        if ($totalToDelete === 0) {
            $this->command->info('   No existing SMS transactions to clear.');
            $this->command->newLine();
            return;
        }

        $this->command->info("   Found {$totalToDelete} transactions to delete:");
        $this->command->info("   • SMS Sent: {$sentCount}");
        $this->command->info("   • SMS Received: {$receivedCount}");

        // Delete SMS-related transactions
        DB::beginTransaction();
        try {
            CreditTransaction::whereIn('transaction_type', ['sms_sent', 'sms_received'])->delete();
            DB::commit();

            $this->command->info("   ✅ Successfully cleared {$totalToDelete} transactions");
            $this->command->comment("   💡 'load' transactions (manual credit loading) were preserved");

            Log::info("Cleared {$totalToDelete} SMS-related credit transactions before repopulating");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error("   ❌ Failed to clear transactions: " . $e->getMessage());
            Log::error("Failed to clear SMS transactions: " . $e->getMessage());
            throw $e;
        }

        $this->command->newLine();
    }

    /**
     * Process sent SMS messages and create credit transactions
     */
    protected function processSentMessages(): array
    {
        $created = 0;
        $skipped = 0;

        // Get all sent messages (we already cleared existing transactions)
        $sentMessages = SMSInbox::where('status', 'sent')
            ->whereNotNull('credits_count')
            ->orderBy('created_at', 'asc')
            ->get();

        $total = $sentMessages->count();
        $this->command->info("Found {$total} sent messages to process");

        if ($total === 0) {
            $this->command->comment('   No sent messages found');
            return ['sent_created' => 0, 'sent_skipped' => 0];
        }

        $bar = $this->command->getOutput()->createProgressBar($total);
        $bar->start();

        $runningBalance = 0; // We'll calculate a fictitious running balance for historical data

        foreach ($sentMessages as $sms) {
            try {
                // Calculate balance before and after (simulated for historical data)
                $balanceBefore = $runningBalance;
                $balanceAfter = $balanceBefore - $sms->credits_count;
                $runningBalance = $balanceAfter;

                CreditTransaction::create([
                    'type' => 'subtract',
                    'amount' => $sms->credits_count,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'transaction_type' => 'sms_sent',
                    'description' => "SMS sent to {$sms->phone_number}" . ($sms->member_id ? " (Member ID: {$sms->member_id})" : ""),
                    'sms_inbox_id' => $sms->id,
                    'created_at' => $sms->created_at,
                    'updated_at' => $sms->updated_at,
                ]);

                $created++;
            } catch (\Exception $e) {
                $skipped++;
                Log::error("Failed to create credit transaction for SMS {$sms->id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();

        return ['sent_created' => $created, 'sent_skipped' => $skipped];
    }

    /**
     * Process received survey responses and create credit transactions
     */
    protected function processReceivedResponses(): array
    {
        $created = 0;
        $skipped = 0;

        // Get all survey responses (we already cleared existing transactions)
        $responses = SurveyResponse::whereNotNull('survey_response')
            ->orderBy('created_at', 'asc')
            ->get();

        $total = $responses->count();
        $this->command->info("Found {$total} survey responses to process");

        if ($total === 0) {
            $this->command->comment('   No survey responses found');
            return ['received_created' => 0, 'received_skipped' => 0];
        }

        $bar = $this->command->getOutput()->createProgressBar($total);
        $bar->start();

        $runningBalance = 0; // Continue from sent messages balance

        foreach ($responses as $response) {
            try {
                // Calculate credits for the response message
                $creditsCount = SMSInbox::calculateCredits($response->survey_response);

                // Calculate balance before and after (simulated for historical data)
                $balanceBefore = $runningBalance;
                $balanceAfter = $balanceBefore - $creditsCount;
                $runningBalance = $balanceAfter;

                $memberName = $response->member ? $response->member->name : 'Unknown';
                $preview = mb_substr($response->survey_response, 0, 50);
                if (mb_strlen($response->survey_response) > 50) {
                    $preview .= '...';
                }

                CreditTransaction::create([
                    'type' => 'subtract',
                    'amount' => $creditsCount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'transaction_type' => 'sms_received',
                    'description' => "SMS received from {$memberName}: {$preview}",
                    'survey_response_id' => $response->id,
                    'created_at' => $response->created_at,
                    'updated_at' => $response->updated_at,
                ]);

                $created++;
            } catch (\Exception $e) {
                $skipped++;
                Log::error("Failed to create credit transaction for response {$response->id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();

        return ['received_created' => $created, 'received_skipped' => $skipped];
    }

    /**
     * Display summary of operations
     */
    protected function displaySummary(array $stats, int $initialBalance, int $newBalance): void
    {
        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('📊 SUMMARY');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->newLine();

        $this->command->info('🗑️  Cleanup:');
        $this->command->info('   • Cleared all existing sms_sent and sms_received transactions');
        $this->command->info('   • Manual credit loads (load transactions) were preserved');
        $this->command->newLine();

        $this->command->info('📤 Sent Messages:');
        $this->command->info("   • Created: {$stats['sent_created']} transactions");
        if ($stats['sent_skipped'] > 0) {
            $this->command->warn("   • Skipped: {$stats['sent_skipped']} (errors)");
        }
        $this->command->newLine();

        $this->command->info('📥 Received Responses:');
        $this->command->info("   • Created: {$stats['received_created']} transactions");
        if ($stats['received_skipped'] > 0) {
            $this->command->warn("   • Skipped: {$stats['received_skipped']} (errors)");
        }
        $this->command->newLine();

        $totalCreated = $stats['sent_created'] + $stats['received_created'];
        $totalSkipped = $stats['sent_skipped'] + $stats['received_skipped'];

        $this->command->info('📈 Total:');
        $this->command->info("   • Transactions created: {$totalCreated}");
        if ($totalSkipped > 0) {
            $this->command->warn("   • Total skipped: {$totalSkipped}");
        }
        $this->command->newLine();

        $this->command->info('💰 SMS Credit Balance:');
        $this->command->info("   • Before: " . number_format($initialBalance));
        $this->command->info("   • After: " . number_format($newBalance));

        $difference = $newBalance - $initialBalance;
        if ($difference != 0) {
            $sign = $difference > 0 ? '+' : '';
            $color = $difference > 0 ? 'info' : 'warn';
            $this->command->{$color}("   • Change: {$sign}" . number_format($difference));
        }
        $this->command->newLine();

        $this->command->comment('⚠️  NOTE: Balance values in historical transactions are simulated.');
        $this->command->comment('    They represent a running tally starting from 0, not actual balances at the time.');
        $this->command->comment('    The NEW balance shown above is calculated from all credit transactions.');
        $this->command->newLine();

        $this->command->info('✅ Historical credit transactions populated successfully!');
    }
}

