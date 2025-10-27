<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use App\Models\{Member, SaccoProduct, MemberSavingsAccount};
use App\Services\SavingsService;

class SavingsDeposit extends Page implements HasForms
{
    use InteractsWithForms;
    
    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';
    
    protected static ?string $navigationGroup = 'SACCO Management';
    
    protected static ?int $navigationSort = 4;
    
    protected static ?string $navigationLabel = 'Deposit Savings';
    
    protected static string $view = 'filament.pages.savings-deposit';
    
    public ?array $data = [];
    
    public function mount(): void
    {
        $this->form->fill();
    }
    
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Deposit Information')
                    ->description('Record a savings deposit for a member')
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Select Member')
                            ->options(Member::query()->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('savings_account_id', null);
                            })
                            ->helperText('Select the member making the deposit'),
                        
                        Forms\Components\Select::make('savings_account_id')
                            ->label('Savings Account')
                            ->options(function (callable $get) {
                                $memberId = $get('member_id');
                                if (!$memberId) {
                                    return [];
                                }
                                
                                $accounts = MemberSavingsAccount::where('member_id', $memberId)
                                    ->where('status', 'active')
                                    ->with('product')
                                    ->get();
                                
                                if ($accounts->isEmpty()) {
                                    return ['create_new' => '➕ Open New Savings Account'];
                                }
                                
                                $options = $accounts->mapWithKeys(function ($account) {
                                    return [
                                        $account->id => "{$account->product->name} - {$account->account_number} (Balance: KES " . number_format($account->balance, 2) . ")"
                                    ];
                                })->toArray();
                                
                                $options['create_new'] = '➕ Open New Savings Account';
                                
                                return $options;
                            })
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->helperText('Select existing account or create new one'),
                        
                        Forms\Components\Select::make('sacco_product_id')
                            ->label('Savings Product')
                            ->options(function () {
                                return SaccoProduct::active()
                                    ->ofType('member-savings')
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->visible(fn (callable $get) => $get('savings_account_id') === 'create_new')
                            ->helperText('Select product for new account'),
                        
                        Forms\Components\TextInput::make('amount')
                            ->label('Deposit Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->prefix('KES')
                            ->placeholder('1000.00')
                            ->helperText('Enter the amount to deposit'),
                        
                        Forms\Components\Select::make('payment_method')
                            ->label('Payment Method')
                            ->options([
                                'cash' => 'Cash',
                                'bank_transfer' => 'Bank Transfer',
                                'mobile_money' => 'Mobile Money (M-PESA/Airtel)',
                                'cheque' => 'Cheque',
                            ])
                            ->required()
                            ->default('cash'),
                        
                        Forms\Components\TextInput::make('reference_number')
                            ->label('Reference Number')
                            ->maxLength(100)
                            ->placeholder('e.g., TXN123456, CHQ789012')
                            ->helperText('Optional: Transaction reference or receipt number'),
                        
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->placeholder('Optional notes about this deposit')
                            ->columnSpanFull(),
                    ])->columns(2),
            ])
            ->statePath('data');
    }
    
    public function submit(): void
    {
        $data = $this->form->getState();
        
        try {
            $savingsService = app(SavingsService::class);
            $member = Member::findOrFail($data['member_id']);
            
            // Handle account creation if needed
            if ($data['savings_account_id'] === 'create_new') {
                if (empty($data['sacco_product_id'])) {
                    Notification::make()
                        ->title('Error')
                        ->danger()
                        ->body('Please select a savings product for the new account.')
                        ->send();
                    return;
                }
                
                $product = SaccoProduct::findOrFail($data['sacco_product_id']);
                $savingsAccount = $savingsService->openSavingsAccount($member, $product);
                
                Notification::make()
                    ->title('New Account Created')
                    ->success()
                    ->body("Savings account {$savingsAccount->account_number} opened successfully.")
                    ->send();
            } else {
                $savingsAccount = MemberSavingsAccount::findOrFail($data['savings_account_id']);
            }
            
            // Process deposit
            $result = $savingsService->deposit(
                $savingsAccount,
                $data['amount'],
                $data['payment_method'],
                $data['reference_number'] ?? null,
                $data['notes'] ?? null
            );
            
            Notification::make()
                ->title('Deposit Successful')
                ->success()
                ->body("Deposited KES " . number_format($data['amount'], 2) . ". New balance: KES " . number_format($result['new_balance'], 2))
                ->send();
            
            // Reset form
            $this->form->fill();
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Deposit Failed')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}

