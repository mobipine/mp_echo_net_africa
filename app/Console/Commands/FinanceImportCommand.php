<?php

namespace App\Console\Commands;

use App\Imports\FinanceBatchImport;
use App\Imports\FinanceBatchImportPreview;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class FinanceImportCommand extends Command
{
    protected $signature = 'finance:import
                            {path : Path to the .xlsx file (relative to project root or absolute)}
                            {--dry-run : Analyze the file and report what would happen, without writing anything}
                            {--test-size=1000 : Number of leading rows assigned to the Finance Test Batch group}';

    protected $description = 'Import ENAF members for the Finance survey, splitting them into a test batch (first N) and a main batch (the rest)';

    public function handle(): int
    {
        $path = $this->argument('path');
        $testSize = (int) $this->option('test-size');

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            $this->line('Tip: place the file at storage/app/imports/enaf_data.xlsx and pass that path.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($path, $testSize);
        }

        return $this->realImport($path, $testSize);
    }

    protected function dryRun(string $path, int $testSize): int
    {
        $this->info('🔍 DRY RUN — nothing will be written.');
        $this->line("File: {$path}");
        $this->line("Test batch size: {$testSize}");
        $this->newLine();

        $preview = new FinanceBatchImportPreview($testSize);
        Excel::import($preview, $path);
        $r = $preview->result();

        $this->table(['Metric', 'Count'], [
            ['Total rows', number_format($r['total'])],
            ['New members (create)', number_format($r['create'])],
            ['Existing members (update)', number_format($r['update'])],
            ['Row errors', number_format($r['errors'])],
            ['— Reachable (has phone)', number_format($r['reachable'])],
            ['— Blank phone', number_format($r['blank_phone'])],
            ['— Invalid phone (saved blank)', number_format($r['invalid_phone'])],
            ['Duplicate national IDs in file', number_format($r['duplicate_in_file'])],
            ['Blank national IDs (-> 00000000)', number_format($r['blank_national_id'])],
            ['New named groups to be created', number_format($r['new_groups'])],
        ]);

        $this->newLine();
        $this->info('Batch split (recipients who will actually get an SMS = reachable):');
        $this->table(['Batch', 'Total', 'Reachable'], [
            [FinanceBatchImport::TEST_GROUP_NAME, number_format($r['test_total']), number_format($r['test_reachable'])],
            [FinanceBatchImport::MAIN_GROUP_NAME, number_format($r['main_total']), number_format($r['main_reachable'])],
        ]);

        $this->newLine();
        $this->comment('Credit estimate (Finance survey, ~16 credits/member to fully complete, 1 credit for the initial question):');
        $this->line('  Test initial send:  ~' . number_format($r['test_reachable']) . ' credits');
        $this->line('  Test full complete: ~' . number_format($r['test_reachable'] * 16) . ' credits (sent only)');
        $this->line('  Full initial send:  ~' . number_format($r['reachable']) . ' credits');
        $this->line('  Full full complete: ~' . number_format($r['reachable'] * 16) . ' credits (sent only; inbound replies billed on top)');
        $this->newLine();
        $this->info('No changes were made. Re-run without --dry-run to import.');

        return self::SUCCESS;
    }

    protected function realImport(string $path, int $testSize): int
    {
        $this->info('📥 Importing members…');
        $this->line("File: {$path}");
        $this->line("Test batch size: {$testSize}");
        $this->newLine();

        $import = new FinanceBatchImport($testSize);
        Excel::import($import, $path);
        $r = $import->result();

        $this->info('✅ Import complete.');
        $this->table(['Metric', 'Count'], [
            ['Rows processed', number_format($r['total_rows'])],
            ['New members created', number_format($r['imported'])],
            ['Existing members updated', number_format($r['updated'])],
            ['Skipped (errors)', number_format($r['skipped'])],
        ]);

        $this->newLine();
        $this->info('Batch groups (use these in the "Dispatch Survey To Multiple Groups" admin page):');
        $this->table(['Group', 'ID'], [
            [$r['test_group_name'], $r['test_group_id']],
            [$r['main_group_name'], $r['main_group_id']],
        ]);

        $this->newLine();
        $this->comment('Next: php artisan survey:estimate-cost 7 --groups=' . $r['test_group_id']
            . '   (then ' . $r['test_group_id'] . ',' . $r['main_group_id'] . ' for the full run)');

        return self::SUCCESS;
    }
}
