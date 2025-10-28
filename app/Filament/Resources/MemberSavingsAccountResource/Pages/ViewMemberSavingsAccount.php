<?php

namespace App\Filament\Resources\MemberSavingsAccountResource\Pages;

use App\Filament\Resources\MemberSavingsAccountResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewMemberSavingsAccount extends ViewRecord
{
    protected static string $resource = MemberSavingsAccountResource::class;
    
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Account Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('account_number')
                            ->label('Account Number')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('member.name')
                            ->label('Member'),
                        Infolists\Components\TextEntry::make('product.name')
                            ->label('Product'),
                        Infolists\Components\TextEntry::make('balance')
                            ->label('Current Balance')
                            ->money('KES')
                            ->color('success')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(\Filament\Support\Enums\FontWeight::Bold),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'dormant' => 'warning',
                                'closed' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('opening_date')
                            ->date(),
                        Infolists\Components\TextEntry::make('closed_date')
                            ->date()
                            ->visible(fn ($record) => $record->status === 'closed'),
                    ])->columns(3),
                
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

