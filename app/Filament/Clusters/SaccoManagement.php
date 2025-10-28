<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Illuminate\Support\Facades\Auth;

class SaccoManagement extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'SACCO Management';

    protected static ?int $navigationSort = 1;

    // Control visibility in the navigation
    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && Auth::user()->can('cluster_SaccoManagement');
    }
}
