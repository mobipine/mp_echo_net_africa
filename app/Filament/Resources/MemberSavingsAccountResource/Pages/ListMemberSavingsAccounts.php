<?php

namespace App\Filament\Resources\MemberSavingsAccountResource\Pages;

use App\Filament\Resources\MemberSavingsAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMemberSavingsAccounts extends ListRecords
{
    protected static string $resource = MemberSavingsAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
    
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Accounts'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),
            'dormant' => Tab::make('Dormant')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'dormant')),
            'closed' => Tab::make('Closed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
        ];
    }
}

