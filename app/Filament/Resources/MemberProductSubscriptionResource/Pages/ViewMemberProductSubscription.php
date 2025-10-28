<?php

namespace App\Filament\Resources\MemberProductSubscriptionResource\Pages;

use App\Filament\Resources\MemberProductSubscriptionResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewMemberProductSubscription extends ViewRecord
{
    protected static string $resource = MemberProductSubscriptionResource::class;
    
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Subscription Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('member.name')
                            ->label('Member'),
                        Infolists\Components\TextEntry::make('product.name')
                            ->label('Product'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'info',
                                'completed' => 'success',
                                'cancelled' => 'danger',
                                'suspended' => 'warning',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('subscription_date')
                            ->date(),
                        Infolists\Components\TextEntry::make('start_date')
                            ->date(),
                        Infolists\Components\TextEntry::make('end_date')
                            ->date()
                            ->placeholder('Ongoing'),
                    ])->columns(3),
                
                Infolists\Components\Section::make('Payment Summary')
                    ->schema([
                        Infolists\Components\TextEntry::make('total_paid')
                            ->label('Total Paid')
                            ->money('KES')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(\Filament\Support\Enums\FontWeight::Bold)
                            ->color('success'),
                        Infolists\Components\TextEntry::make('total_expected')
                            ->label('Total Expected')
                            ->money('KES')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(\Filament\Support\Enums\FontWeight::Bold),
                        Infolists\Components\TextEntry::make('outstanding_amount')
                            ->label('Outstanding')
                            ->money('KES')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight(\Filament\Support\Enums\FontWeight::Bold)
                            ->color('warning'),
                        Infolists\Components\TextEntry::make('payment_count')
                            ->label('Number of Payments'),
                        Infolists\Components\TextEntry::make('last_payment_date')
                            ->date()
                            ->placeholder('No payments yet'),
                        Infolists\Components\TextEntry::make('next_payment_date')
                            ->date()
                            ->color(fn ($record) => $record->next_payment_date && $record->next_payment_date < now() ? 'danger' : null),
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

