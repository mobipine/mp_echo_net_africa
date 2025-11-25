<?php

namespace Database\Seeders;

use App\Models\SMSInbox;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSmsInboxCreditsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Starting to update credits_count for all SMS inbox records...');

        $totalRecords = SMSInbox::count();
        $this->command->info("Total records to process: {$totalRecords}");

        $updated = 0;
        $bar = $this->command->getOutput()->createProgressBar($totalRecords);
        $bar->start();

        // Process in chunks to avoid memory issues
        SMSInbox::whereNull('credits_count')
            ->orWhere('credits_count', 0)
            ->chunk(1000, function ($records) use (&$updated, $bar) {
                foreach ($records as $record) {
                    if ($record->message) {
                        $creditsCount = SMSInbox::calculateCredits($record->message);

                        DB::table('sms_inboxes')
                            ->where('id', $record->id)
                            ->update(['credits_count' => $creditsCount]);

                        $updated++;
                    }
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info("✅ Successfully updated {$updated} records!");

        Log::info("UpdateSmsInboxCreditsSeeder: Updated {$updated} out of {$totalRecords} records");
    }
}

