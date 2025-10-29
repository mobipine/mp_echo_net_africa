<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use App\Services\GroupTransactionService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;

class ViewGroup extends ViewRecord
{
    protected static string $resource = GroupResource::class;

    public ?array $data = [];
    
    public array $financialSummary = [];

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        
        // Calculate financial summary
        $groupTransactionService = app(GroupTransactionService::class);
        $this->financialSummary = $groupTransactionService->getGroupFinancialSummary($this->record);
        
        // Populate data
        $this->data = [
            'name' => $this->record->name,
            'email' => $this->record->email,
            'phone_number' => $this->record->phone_number,
            'registration_number' => $this->record->registration_number,
            'formation_date' => $this->record->formation_date?->format('Y-m-d'),
            'county' => $this->record->county,
            'sub_county' => $this->record->sub_county,
            'ward' => $this->record->ward,
            'township' => $this->record->township,
            'address' => $this->record->address,
            'total_members' => $this->record->members()->count(),
            'total_assets' => $this->financialSummary['total_assets'] ?? 0,
            'total_liabilities' => $this->financialSummary['total_liabilities'] ?? 0,
            'total_revenue' => $this->financialSummary['total_revenue'] ?? 0,
            'total_expenses' => $this->financialSummary['total_expenses'] ?? 0,
            'net_income' => $this->financialSummary['net_income'] ?? 0,
            'equity_balance' => $this->financialSummary['equity_balance'] ?? 0,
            'bank_balance' => $this->record->bank_balance,
            'total_capital_advanced' => $this->record->total_capital_advanced,
            'total_capital_returned' => $this->record->total_capital_returned,
            'net_capital_outstanding' => $this->record->net_capital_outstanding,
        ];
        
        $this->form->fill($this->data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema($this->getFormSchema())
                    ->statePath('data')
                    ->disabled()
            ),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('Financial Overview')
                ->description('Key financial metrics and performance indicators for this group')
                ->schema([
                    Grid::make(4)->schema([
                        Forms\Components\TextInput::make('total_assets')
                            ->label('Total Assets')
                            ->prefix('KES')
                            ->numeric()
                            ->extraAttributes(['class' => 'text-green-600 font-bold']),
                        
                        Forms\Components\TextInput::make('total_liabilities')
                            ->label('Total Liabilities')
                            ->prefix('KES')
                            ->numeric()
                            ->extraAttributes(['class' => 'text-red-600 font-bold']),
                        
                        // Forms\Components\TextInput::make('equity_balance')
                        //     ->label('Equity')
                        //     ->prefix('KES')
                        //     ->numeric()
                        //     ->extraAttributes(['class' => 'text-blue-600 font-bold']),
                        
                        // Forms\Components\TextInput::make('net_income')
                        //     ->label('Net Income')
                        //     ->prefix('KES')
                        //     ->numeric()
                        //     ->extraAttributes(['class' => 'text-purple-600 font-bold']),
                    ]),
                ])
                ->collapsible(),
            
            Tabs::make('Group Details')
                ->tabs([
                    Tab::make('Basic Information')
                        ->icon('heroicon-o-information-circle')
                        ->schema($this->getBasicInformationSchema()),
                    
                    Tab::make('Financial Metrics')
                        ->icon('heroicon-o-currency-dollar')
                        ->schema($this->getFinancialMetricsSchema()),
                    
                    Tab::make('Location Details')
                        ->icon('heroicon-o-map-pin')
                        ->schema($this->getLocationSchema()),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getBasicInformationSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Group Name'),
                
                Forms\Components\TextInput::make('registration_number')
                    ->label('Registration Number'),
                
                Forms\Components\TextInput::make('email')
                    ->label('Email'),
                
                Forms\Components\TextInput::make('phone_number')
                    ->label('Phone Number'),
                
                Forms\Components\TextInput::make('formation_date')
                    ->label('Formation Date'),
                
                Forms\Components\TextInput::make('total_members')
                    ->label('Total Members')
                    ->suffix('members'),
            ]),
        ];
    }

    protected function getFinancialMetricsSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('bank_balance')
                    ->label('Bank Balance')
                    ->prefix('KES')
                    ->numeric(),
                
                Forms\Components\TextInput::make('total_capital_advanced')
                    ->label('Capital Advanced from Organization')
                    ->prefix('KES')
                    ->numeric(),
                
                Forms\Components\TextInput::make('total_capital_returned')
                    ->label('Capital Returned to Organization')
                    ->prefix('KES')
                    ->numeric(),
                
                Forms\Components\TextInput::make('net_capital_outstanding')
                    ->label('Net Capital Outstanding')
                    ->prefix('KES')
                    ->numeric(),
                
                Forms\Components\TextInput::make('total_revenue')
                    ->label('Total Revenue')
                    ->prefix('KES')
                    ->numeric(),
                
                Forms\Components\TextInput::make('total_expenses')
                    ->label('Total Expenses')
                    ->prefix('KES')
                    ->numeric(),
            ]),
        ];
    }

    protected function getLocationSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('county')
                    ->label('County'),
                
                Forms\Components\TextInput::make('sub_county')
                    ->label('Sub County'),
                
                Forms\Components\TextInput::make('ward')
                    ->label('Ward'),
                
                Forms\Components\TextInput::make('township')
                    ->label('Township'),
                
                Forms\Components\Textarea::make('address')
                    ->label('Physical Address')
                    ->rows(3)
                    ->columnSpanFull(),
            ]),
        ];
    }
}

