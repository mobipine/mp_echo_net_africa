<?php

namespace App\Filament\Pages;

use App\Models\CreditTransaction;
use App\Filament\Widgets\CreditStatsWidget;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

class CreditReports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static string $view = 'filament.pages.credit-reports';
    protected static ?string $navigationGroup = 'SMS & Credits';
    protected static ?string $title = 'Credit Reports & Transactions';
    protected static ?int $navigationSort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(CreditTransaction::query()->latest())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Time')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Type')
                    ->colors([
                        'success' => 'add',
                        'danger' => 'subtract',
                    ])
                    ->icons([
                        'heroicon-o-plus-circle' => 'add',
                        'heroicon-o-minus-circle' => 'subtract',
                    ]),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Transaction')
                    ->badge()
                    ->colors([
                        'primary' => 'load',
                        'warning' => 'sms_sent',
                        'info' => 'sms_received',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'load' => 'Credits Loaded',
                        'sms_sent' => 'SMS Sent',
                        'sms_received' => 'SMS Received',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Credits')
                    ->formatStateUsing(fn ($state, $record) =>
                        ($record->type === 'add' ? '+' : '-') . number_format($state)
                    )
                    ->color(fn ($record) => $record->type === 'add' ? 'success' : 'danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('balance_before')
                    ->label('Before')
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('After')
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->toggleable()
                    ->placeholder('System'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'add' => 'Additions',
                        'subtract' => 'Subtractions',
                    ]),

                Tables\Filters\SelectFilter::make('transaction_type')
                    ->label('Transaction Type')
                    ->options([
                        'load' => 'Credits Loaded',
                        'sms_sent' => 'SMS Sent',
                        'sms_received' => 'SMS Received',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->native(false),
                        \Filament\Forms\Components\DatePicker::make('until')->native(false),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('created_at', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->whereDate('created_at', '<=', $data['until']));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CreditStatsWidget::class,
        ];
    }
}

