<?php

namespace App\Filament\Resources\MemberFeeObligationResource\Pages;

use App\Filament\Resources\MemberFeeObligationResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewMemberFeeObligation extends ViewRecord
{
    protected static string $resource = MemberFeeObligationResource::class;
    
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Obligation Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('member.name')
                            ->label('Member'),
                        Infolists\Components\TextEntry::make('saccoProduct.name')
                            ->label('Fee/Fine'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'partially_paid' => 'info',
                                'paid' => 'success',
                                'waived' => 'secondary',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('due_date')
                            ->date()
                            ->color(fn ($record) => $record->due_date->isPast() && $record->balance_due > 0 ? 'danger' : null),
                    ])->columns(4),
                
                Infolists\Components\Section::make('Payment Summary')
                    ->schema([
                        Infolists\Components\TextEntry::make('amount_due')
                            ->label('Amount Due')
                            ->money('KES')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(\Filament\Support\Enums\FontWeight::Bold),
                        Infolists\Components\TextEntry::make('amount_paid')
                            ->label('Amount Paid')
                            ->money('KES')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(\Filament\Support\Enums\FontWeight::Bold)
                            ->color('success'),
                        Infolists\Components\TextEntry::make('balance_due')
                            ->label('Balance Due')
                            ->money('KES')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(\Filament\Support\Enums\FontWeight::Bold)
                            ->color('warning'),
                    ])->columns(3),
                
                Infolists\Components\Section::make('Description')
                    ->schema([
                        Infolists\Components\TextEntry::make('description')
                            ->placeholder('No description')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => !empty($record->description)),
                
                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->placeholder('No notes')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => !empty($record->notes)),
            ]);
    }
}

