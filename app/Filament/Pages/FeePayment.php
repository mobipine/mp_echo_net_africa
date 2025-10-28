<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use App\Models\{Member, SaccoProduct, MemberFeeObligation};
use App\Services\{FeePaymentService, FeeAccrualService};

class FeePayment extends Page implements HasForms
{
    use InteractsWithForms;
    
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    
    // protected static ?string $navigationGroup = 'SACCO Management';
    protected static ?string $cluster = \App\Filament\Clusters\SaccoManagement::class;
    
    protected static ?int $navigationSort = 8;
    
    protected static ?string $navigationLabel = 'Fee Payment';
    
    protected static string $view = 'filament.pages.fee-payment';
    
    public ?array $data = [];
    
    public function mount(): void
    {
        $this->form->fill();
    }
    
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Fee Payment Information')
                    ->description('Record a fee or fine payment for a member')
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Select Member')
                            ->options(Member::query()->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('obligation_id', null);
                            })
                            ->helperText('Select the member making the payment'),
                        
                        Forms\Components\Select::make('obligation_id')
                            ->label('Fee/Fine Owed')
                            ->options(function (callable $get) {
                                $memberId = $get('member_id');
                                if (!$memberId) {
                                    return [];
                                }
                                
                                $member = Member::find($memberId);
                                if (!$member) {
                                    return [];
                                }
                                
                                // Get pending obligations
                                $obligations = MemberFeeObligation::where('member_id', $memberId)
                                    ->whereIn('status', ['pending', 'partially_paid'])
                                    ->with('saccoProduct')
                                    ->orderBy('due_date')
                                    ->get();
                                
                                if ($obligations->isEmpty()) {
                                    return ['no_obligations' => '✅ No pending fees'];
                                }
                                
                                return $obligations->mapWithKeys(function ($obligation) {
                                    $badge = $obligation->status === 'partially_paid' ? '⚠️' : '❗';
                                    $dueDate = $obligation->due_date->format('M d, Y');
                                    $overdue = $obligation->due_date->isPast() ? ' (OVERDUE)' : '';
                                    return [
                                        $obligation->id => "{$badge} {$obligation->saccoProduct->name} - Due: {$dueDate}{$overdue} - Balance: KES " . number_format($obligation->balance_due, 2)
                                    ];
                                })->toArray();
                            })
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->helperText('Select the fee to pay'),
                        
                        Forms\Components\Placeholder::make('obligation_info')
                            ->label('Fee Details')
                            ->content(function (callable $get) {
                                $obligationId = $get('obligation_id');
                                
                                if (!$obligationId || $obligationId === 'no_obligations') {
                                    return 'Select a fee to see details';
                                }
                                
                                $obligation = MemberFeeObligation::find($obligationId);
                                
                                if (!$obligation) {
                                    return 'Obligation not found';
                                }
                                
                                $overdueClass = $obligation->due_date->isPast() ? 'text-danger-600 font-bold' : '';
                                
                                return new \Illuminate\Support\HtmlString("
                                    <div class='space-y-2 p-4 bg-gray-50 rounded-lg'>
                                        <div><strong>Fee:</strong> {$obligation->saccoProduct->name}</div>
                                        <div><strong>Amount Due:</strong> KES " . number_format($obligation->amount_due, 2) . "</div>
                                        <div><strong>Amount Paid:</strong> <span class='text-success-600'>KES " . number_format($obligation->amount_paid, 2) . "</span></div>
                                        <div><strong>Balance Due:</strong> <span class='text-warning-600 font-bold text-lg'>KES " . number_format($obligation->balance_due, 2) . "</span></div>
                                        <div><strong>Due Date:</strong> <span class='{$overdueClass}'>" . $obligation->due_date->format('d M Y') . "</span></div>
                                        <div><strong>Status:</strong> <span class='capitalize'>{$obligation->status}</span></div>
                                    </div>
                                ");
                            })
                            ->visible(fn (callable $get) => $get('obligation_id') && $get('obligation_id') !== 'no_obligations'),
                        
                        Forms\Components\TextInput::make('amount')
                            ->label('Payment Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->prefix('KES')
                            ->placeholder('300.00')
                            ->reactive()
                            ->rules([
                                function (callable $get) {
                                    return function ($attribute, $value, $fail) use ($get) {
                                        $obligationId = $get('obligation_id');
                                        if ($obligationId && $obligationId !== 'no_obligations') {
                                            $obligation = MemberFeeObligation::find($obligationId);
                                            if ($obligation && $value > $obligation->balance_due) {
                                                $fail('Payment amount exceeds balance due of KES ' . number_format($obligation->balance_due, 2));
                                            }
                                        }
                                    };
                                }
                            ])
                            ->helperText('Enter the amount being paid (can be partial payment)')
                            ->visible(fn (callable $get) => $get('obligation_id') && $get('obligation_id') !== 'no_obligations'),
                        
                        Forms\Components\Select::make('payment_method')
                            ->label('Payment Method')
                            ->options([
                                'cash' => 'Cash',
                                'bank_transfer' => 'Bank Transfer',
                                'mobile_money' => 'Mobile Money (M-PESA/Airtel)',
                                'cheque' => 'Cheque',
                            ])
                            ->required()
                            ->default('cash')
                            ->visible(fn (callable $get) => $get('obligation_id') && $get('obligation_id') !== 'no_obligations'),
                        
                        Forms\Components\TextInput::make('reference_number')
                            ->label('Reference Number')
                            ->maxLength(100)
                            ->placeholder('e.g., MPESA-ABC123, CHQ-456')
                            ->helperText('Optional: Transaction reference')
                            ->visible(fn (callable $get) => $get('obligation_id') && $get('obligation_id') !== 'no_obligations'),
                        
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->placeholder('Optional notes about this payment')
                            ->columnSpanFull()
                            ->visible(fn (callable $get) => $get('obligation_id') && $get('obligation_id') !== 'no_obligations'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }
    
    public function submit(): void
    {
        $data = $this->form->getState();
        
        if ($data['obligation_id'] === 'no_obligations') {
            Notification::make()
                ->title('No Pending Fees')
                ->info()
                ->body('This member has no pending fees to pay.')
                ->send();
            return;
        }
        
        try {
            $feePaymentService = app(FeePaymentService::class);
            $feeAccrualService = app(FeeAccrualService::class);
            
            $obligation = MemberFeeObligation::findOrFail($data['obligation_id']);
            $member = $obligation->member;
            $product = $obligation->saccoProduct;
            
            // Record payment
            $result = $feePaymentService->recordPayment(
                $member,
                $product,
                $data['amount'],
                $data['payment_method'],
                $data['reference_number'] ?? null,
                $data['notes'] ?? null
            );
            
            // Update obligation
            $feeAccrualService->recordPayment($obligation, $data['amount']);
            
            $message = "Payment of KES " . number_format($data['amount'], 2) . " for {$product->name} recorded successfully.";
            if ($obligation->status === 'paid') {
                $message .= " Fee fully paid!";
            } else {
                $message .= " Balance due: KES " . number_format($obligation->balance_due, 2);
            }
            
            Notification::make()
                ->title('Payment Successful')
                ->success()
                ->body($message)
                ->send();
            
            // Reset form
            $this->form->fill();
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Payment Failed')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}
