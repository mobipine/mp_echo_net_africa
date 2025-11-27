<?php

namespace App\Filament\Pages;

use App\Models\CreditTransaction;
use App\Filament\Widgets\CreditStatsWidget;
use App\Filament\Widgets\SentSmsStatsOverview as WidgetsSentSmsStatsOverview;
use App\Models\SMSInbox;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SmsStatsExport;

class SmsReports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static string $view = 'filament.pages.sms-reports';
    protected static ?string $navigationGroup = 'Analytics';
    protected static ?string $title = 'Sms Reports';
    protected static ?int $navigationSort = 3;

    public function table(Table $table): Table
    {
        
        return $table
            ->query(SMSInbox::query()->latest())
            ->columns([
                 TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('message')->label('Message')->limit(50)->searchable(),
            TextColumn::make('member.name')
                ->label('Member')
                ->formatStateUsing(fn ($state, $record) =>
                    $state ?? $record->phone_number
                )
                ->searchable(['member.name', 'phone_number'])
                ->sortable(),
            TextColumn::make('phone_number'),
            // TextColumn::make('group_ids')
            //     ->label('Groups')
            //     ->formatStateUsing(function ($state) {
            //         // dd($state);
            //         $groups_array = explode(',', $state);
            //         //get the names of the groups
            //         $group_names = Group::whereIn('id', $groups_array)->pluck('name')->toArray();
            //         // Return the names as a comma-separated string
            //         return is_array($group_names) ? implode(', ', $group_names) : $group_names;
                    
            //         // return is_array($state) ? implode(', ', $state) : $state;
            //     }),
            BadgeColumn::make('status')
                ->label('Status')
                ->colors([
                    'warning' => fn ($state): bool => $state === 'pending',
                    'success' => fn ($state): bool => $state === 'sent',
                    'danger' => fn ($state): bool => $state === 'failed',
                ])
                ->sortable()
                ->formatStateUsing(fn ($state) => ucfirst($state)), // Capitalize the status

                BadgeColumn::make('delivery_status')
                ->label('Delivery Status')
                ->colors([
                    'warning' => fn ($state): bool => $state === 'pending',
                    'success' => fn ($state): bool => $state === 'sent',
                    'danger' => fn ($state): bool => $state === 'failed',
                ])
                ->sortable()
                ->formatStateUsing(fn ($state) => ucfirst($state)), // Capitalize the status

                BadgeColumn::make('delivery_status_desc')
                ->label('Delivery Status Description')
                ->sortable()
                ->formatStateUsing(fn ($state) => ucfirst($state)),
                
                
            ])
            ->filters([
               
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    protected function getActions(): array
    {
        return [
            Action::make('export_stats')
                ->label('Export Stats')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->action(function () {
                    $filters = $this->filters ?? [];
                    return Excel::download(new SmsStatsExport($filters), 'sms_stats_' . now()->format('Y_m_d_H_i_s') . '.xlsx');
                }),
        ];
    }
    protected function getHeaderWidgets(): array
    {
        
        return [
            WidgetsSentSmsStatsOverview::class,
        ];
    }
}

