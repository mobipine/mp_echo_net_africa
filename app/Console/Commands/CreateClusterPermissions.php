<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;

class CreateClusterPermissions extends Command
{
    protected $signature = 'create:cluster-permissions';
    protected $description = 'Create permissions for Filament clusters';

    public function handle()
    {
        $clusters = [
            'SaccoManagement',
            'Settings',
        ];

        foreach ($clusters as $cluster) {
            $permissionName = "cluster_{$cluster}";
            
            Permission::firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => 'web']
            );

            $this->info("Created permission: {$permissionName}");
        }

        $this->info('All cluster permissions created successfully!');
        
        return Command::SUCCESS;
    }
}

