<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use App\Models\{Member, MemberSavingsAccount};
use App\Services\SavingsService;

class SavingsWithdrawal extends Page implements HasForms
{
    use InteractsWithForms;
    
    protected static ?string $navigationIcon = 'heroicon-o-minus-circle';
    
    protected static ?string $cluster = \App\Filament\Clusters\SaccoManagement::class;
    
    protected static ?int $navigationSort = 5;
    
    protected static ?string $navigationLabel = 'Withdraw Savings';
    
    protected static string $view = 'filament.pages.savings-withdrawal';
    
    public ?array $data = [];
    
    public function mount(): void
    {
        $this->form->fill();
    }
    
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Withdrawal Information')
                    ->description('Record a savings withdrawal for a member')
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
                            ->helperText('Select the member making the withdrawal'),
                        
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
                                    return [];
                                }
                                
                                return $accounts->mapWithKeys(function ($account) {
                                    $balance = $account->balance;
                                    $color = $balance > 0 ? '🟢' : '🔴';
                                    return [
                                        $account->id => "{$color} {$account->product->name} - {$account->account_number} (Balance: KES " . number_format($balance, 2) . ")"
                                    ];
                                })->toArray();
                            })
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->helperText('Select account to withdraw from'),
                        
                        Forms\Components\Placeholder::make('current_balance')
                            ->label('Current Balance')
                            ->content(function (callable $get) {
                                $accountId = $get('savings_account_id');
                                if (!$accountId) {
                                    return 'Select an account to see balance';
                                }
                                
                                $account = MemberSavingsAccount::find($accountId);
                                if (!$account) {
                                    return 'Account not found';
                                }
                                
                                $balance = $account->balance;
                                $color = $balance > 0 ? 'success' : 'danger';
                                
                                return new \Illuminate\Support\HtmlString(
                                    '<span class="text-2xl font-bold text-' . $color . '-600">KES ' . number_format($balance, 2) . '</span>'
                                );
                            }),
                        
                        Forms\Components\TextInput::make('amount')
                            ->label('Withdrawal Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->prefix('KES')
                            ->placeholder('1000.00')
                            ->helperText('Enter the amount to withdraw')
                            ->rules([
                                function (callable $get) {
                                    return function ($attribute, $value, $fail) use ($get) {
                                        $accountId = $get('savings_account_id');
                                        if ($accountId) {
                                            $account = MemberSavingsAccount::find($accountId);
                                            if ($account && $value > $account->balance) {
                                                $fail('Withdrawal amount exceeds available balance of KES ' . number_format($account->balance, 2));
                                            }
                                        }
                                    };
                                }
                            ]),
                        
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
                            ->placeholder('e.g., WD123456, CHQ789012')
                            ->helperText('Optional: Transaction reference or receipt number'),
                        
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes/Reason for Withdrawal')
                            ->rows(3)
                            ->placeholder('Optional notes about this withdrawal')
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
            $savingsAccount = MemberSavingsAccount::findOrFail($data['savings_account_id']);
            
            // Process withdrawal
            $result = $savingsService->withdraw(
                $savingsAccount,
                $data['amount'],
                $data['payment_method'],
                $data['reference_number'] ?? null,
                $data['notes'] ?? null
            );
            
            Notification::make()
                ->title('Withdrawal Successful')
                ->success()
                ->body("Withdrawn KES " . number_format($data['amount'], 2) . ". New balance: KES " . number_format($result['new_balance'], 2))
                ->send();
            
            // Reset form
            $this->form->fill();
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Withdrawal Failed')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}

