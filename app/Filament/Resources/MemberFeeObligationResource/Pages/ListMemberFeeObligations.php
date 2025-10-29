<?php

namespace App\Filament\Resources\MemberFeeObligationResource\Pages;

use App\Filament\Resources\MemberFeeObligationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMemberFeeObligations extends ListRecords
{
    protected static string $resource = MemberFeeObligationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
    
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge(fn () => \App\Models\MemberFeeObligation::where('status', 'pending')->count()),
            'partially_paid' => Tab::make('Partially Paid')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'partially_paid')),
            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing(fn (Builder $query) => $query->overdue())
                ->badge(fn () => \App\Models\MemberFeeObligation::overdue()->count())
                ->badgeColor('danger'),
            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid')),
        ];
    }
}

