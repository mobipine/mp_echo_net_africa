<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Builder;

class LoanAmortizationScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'amortizationSchedules';

    protected static ?string $title = 'Amortization Schedule';

    protected static ?string $modelLabel = 'Schedule Entry';

    protected static ?string $pluralModelLabel = 'Schedule Entries';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Read-only form since amortization schedules are auto-generated
                Forms\Components\Placeholder::make('info')
                    ->content('Amortization schedules are automatically generated when loans are approved. They cannot be manually edited.')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_number')
            ->columns([
                TextColumn::make('payment_number')
                    ->label('Payment #')
                    ->sortable()
                    ->alignCenter(),
                    
                TextColumn::make('payment_date')
                    ->label('Due Date')
                    ->date()
                    ->sortable(),
                    
                TextColumn::make('principal_payment')
                    ->label('Principal')
                    ->money('KES')
                    ->sortable(),
                    
                TextColumn::make('interest_payment')
                    ->label('Interest')
                    ->money('KES')
                    ->sortable(),
                    
                TextColumn::make('total_payment')
                    ->label('Total Payment')
                    ->money('KES')
                    ->sortable()
                    ->weight('bold'),
                    
                TextColumn::make('remaining_balance')
                    ->label('Remaining Balance')
                    ->money('KES')
                    ->sortable()
                    ->color(fn ($state) => $state <= 0 ? 'success' : 'warning'),
                    
                // BadgeColumn::make('status')
                //     ->label('Status')
                //     ->colors([
                //         'success' => 'paid',
                //         'warning' => 'pending',
                //         'danger' => 'overdue',
                //     ])
                //     ->formatStateUsing(function ($state, $record) {
                //         // You can implement logic to determine payment status
                //         // For now, we'll show a simple status
                //         $today = now();
                //         if ($record->payment_date < $today) {
                //             return 'Overdue';
                //         } elseif ($record->payment_date == $today) {
                //             return 'Due Today';
                //         } else {
                //             return 'Pending';
                //         }
                //     }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Payment Status')
                    ->options([
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'overdue' => 'Overdue',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (!$data['value']) {
                            return $query;
                        }
                        
                        $today = now();
                        return match ($data['value']) {
                            'overdue' => $query->where('payment_date', '<', $today),
                            'paid' => $query->where('payment_date', '>=', $today), // This would need actual payment tracking
                            'pending' => $query->where('payment_date', '>=', $today),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                // ViewAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // No bulk actions for amortization schedules
                ]),
            ])
            ->defaultSort('payment_number', 'asc')
            ->emptyStateHeading('No Amortization Schedule')
            ->emptyStateDescription('The amortization schedule will be generated when this loan is approved.')
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->paginated(false) // Show all schedule entries on one page
            ->striped();
    }
}
