<?php

namespace App\Filament\Resources\MemberProductSubscriptionResource\Pages;

use App\Filament\Resources\MemberProductSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMemberProductSubscriptions extends ListRecords
{
    protected static string $resource = MemberProductSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
    
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'completed')),
            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing(fn (Builder $query) => 
                    $query->where('status', 'active')
                          ->where('next_payment_date', '<', now())
                ),
        ];
    }
}

