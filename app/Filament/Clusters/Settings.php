<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class Settings extends Cluster
{
    // protected static string $view = 'filament.pages.settings';
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;


    // Control visibility in the navigation
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()->can('page_Settings');
    }
    
}
