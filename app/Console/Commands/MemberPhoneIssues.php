<?php

namespace App\Console\Commands;

use App\Exports\InvalidPhonesExport;
use App\Imports\InvalidPhonesImport;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class MemberPhoneIssues extends Command
{
    protected $signature = 'members:phone-issues
        {file : Path to the members Excel/CSV file (absolute, or relative to storage/app)}
        {--include-missing : Also list rows that have no phone number at all}
        {--out= : Output path relative to storage/app (default: exports/invalid-phones-<timestamp>.xlsx)}';

    protected $description = 'List members whose phone number cannot be normalized, as an Excel file to hand back to the client for fixing.';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!is_file($file)) {
            $alt = storage_path('app/' . ltrim($file, '/'));
            if (is_file($alt)) {
                $file = $alt;
            } else {
                $this->error("File not found: {$file}");
                $this->line('Pass an absolute path, or a path relative to storage/app.');

                return self::FAILURE;
            }
        }

        $this->info("Scanning {$file} ...");

        $collector = new InvalidPhonesImport((bool) $this->option('include-missing'));
        Excel::import($collector, $file);

        $rows = $collector->rows();

        $this->line("Scanned {$collector->scanned()} rows.");

        if (empty($rows)) {
            $this->info('No phone issues found. 🎉');

            return self::SUCCESS;
        }

        $out = $this->option('out') ?: 'exports/invalid-phones-' . now()->format('Y_m_d_His') . '.xlsx';
        Excel::store(new InvalidPhonesExport($rows), $out, 'local');

        $this->warn(count($rows) . ' row(s) have phone issues:');
        $this->table(
            ['Row', 'Group', 'Name', 'National ID', 'Phone', 'Issue'],
            array_map('array_values', array_slice($rows, 0, 20))
        );

        if (count($rows) > 20) {
            $this->line('... and ' . (count($rows) - 20) . ' more (full list in the file).');
        }

        $this->info('Saved: ' . storage_path('app/' . $out));

        return self::SUCCESS;
    }
}
