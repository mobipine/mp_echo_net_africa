<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

/**
 * Converts member phone numbers from E.164 (+254XXXXXXXXX) to the app's canonical
 * local format (0XXXXXXXXX).
 *
 * The inbound webhook, startSurvey, processSurveyResponse and the SMS inbox lookups all
 * match members on the local 0XXX format. A member stored as +254XXX therefore never
 * matches an inbound reply, so their survey responses are silently dropped
 * ("No active survey or trigger word found"). This backfills existing +254 / 254 rows.
 */
class NormalizeMemberPhonesCommand extends Command
{
    protected $signature = 'members:normalize-phones
                            {--dry-run : Report what would change without modifying anything}';

    protected $description = 'Convert member phones from +254XXXXXXXXX / 254XXXXXXXXX to the app\'s local 0XXXXXXXXX format so inbound replies match';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Anything not already in local 0... form that starts with +254 or 254.
        $query = Member::where(function ($q) {
            $q->where('phone', 'like', '+254%')
                ->orWhere('phone', 'like', '254%');
        });

        $total = $query->count();
        $this->info(($dryRun ? '🔍 DRY RUN — ' : '') . "Members with non-local (+254/254) phone format: " . number_format($total));

        if ($total === 0) {
            $this->info('Nothing to convert — all member phones are already in local format.');
            return self::SUCCESS;
        }

        $sample = (clone $query)->limit(8)->pluck('phone')->map(function ($p) {
            return $p . ' -> ' . preg_replace('/^\+?254/', '0', $p);
        })->implode("\n  ");
        $this->newLine();
        $this->line("Sample conversions:\n  " . $sample);
        $this->newLine();

        if ($dryRun) {
            $this->comment('DRY RUN — nothing changed. Re-run without --dry-run to apply.');
            return self::SUCCESS;
        }

        $converted = 0;
        $skipped = 0;
        $query->orderBy('id')->chunkById(1000, function ($members) use (&$converted, &$skipped) {
            foreach ($members as $member) {
                $new = preg_replace('/^\+?254/', '0', $member->phone);

                // Safety: only accept a sane local KE number (0 + 9 digits = 10 chars).
                if (!preg_match('/^0\d{9}$/', $new)) {
                    $skipped++;
                    continue;
                }

                $member->phone = $new;
                $member->save();
                $converted++;
            }
        });

        $this->newLine();
        $this->info("✅ Converted {$converted} member phone(s) to local format." . ($skipped ? " Skipped {$skipped} that did not look like valid KE numbers." : ''));

        return self::SUCCESS;
    }
}
