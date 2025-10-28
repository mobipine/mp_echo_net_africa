<?php

namespace App\Filament\Pages;

use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\RepaymentAllocationService;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class LoanRepaymentPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static string $view = 'filament.pages.loan-repayment';
    protected static ?string $navigationGroup = 'Loan Management';
    protected static ?string $title = 'Loan Repayment';

    public ?array $data = [];
    public $member_id;
    public $loan_id;
    public $amount;
    public $repayment_date;
    public $payment_method;
    public $reference_number;
    public $notes;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && Auth::user()->can('page_LoanRepaymentPage');
    }

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->can('page_LoanRepaymentPage'), 403);
        $this->form->fill();
    }

    protected function getFormSchema(): array
    {
        return [
            Grid::make(3)->schema([
                Select::make('member_id')
                    ->label('Select Member')
                    ->options(Member::all()->pluck('name', 'id')->toArray())
                    ->live()
                    ->reactive()
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->columnSpan(1)
                    ->afterStateUpdated(function ($state, callable $set) {
                        $this->member_id = $state;
                        $set('loan_id', null);
                        $this->dispatch('member-selected');
                    }),

                Select::make('loan_id')
                    ->label('Select Loan')
                    ->options(function () {
                        if (!$this->member_id) {
                            return [];
                        }
                        return Loan::where('member_id', $this->member_id)
                            ->where('status', 'Approved')
                            ->get()
                            ->filter(function ($loan) {
                                return $loan->remaining_balance > 0;
                            })
                            ->mapWithKeys(function ($loan) {
                                $remaining = $loan->remaining_balance;
                                $charges = $loan->getOutstandingLoanCharges();
                                $interest = $loan->getOutstandingInterest();
                                $principal = $loan->getOutstandingPrincipal();
                                
                                $breakdown = [];
                                if ($charges > 0) $breakdown[] = "Charges: KES " . number_format($charges, 2);
                                if ($interest > 0) $breakdown[] = "Interest: KES " . number_format($interest, 2);
                                if ($principal > 0) $breakdown[] = "Principal: KES " . number_format($principal, 2);
                                
                                $breakdownText = $breakdown ? " (" . implode(", ", $breakdown) . ")" : "";
                                
                                return [$loan->id => "{$loan->loan_number} - KES " . number_format($remaining, 2) . " owed{$breakdownText}"];
                            });
                    })
                    ->reactive()
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->afterStateUpdated(function ($state, callable $set) {
                        $this->loan_id = $state;
                        if ($state) {
                            $loan = Loan::find($state);
                            $set('amount', $loan->remaining_balance);
                        }
                    }),

                TextInput::make('amount')
                    ->label('Repayment Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->live()
                    ->reactive()
                    ->columnSpan(1),

                DatePicker::make('repayment_date')
                    ->label('Repayment Date')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->timezone('Africa/Nairobi')
                    ->locale('en')
                    ->required(),

                Select::make('payment_method')
                    ->label('Payment Method')
                    ->options([
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank Transfer',
                        'mobile_money' => 'Mobile Money',
                        'cheque' => 'Cheque',
                        'other' => 'Other',
                    ])
                    ->native(false)
                    ->required(),

                TextInput::make('reference_number')
                    ->label('Reference Number')
                    ->placeholder('Payment reference number (optional)'),

                Textarea::make('notes')
                    ->label('Notes')
                    ->placeholder('Additional notes (optional)')
                    ->rows(3)
                    ->columnSpan(2),
            ]),

            // Enhanced Member Details Section
            Section::make('Member Information')
                ->schema([
                    Grid::make(4)->schema([
                        Placeholder::make('member_avatar')
                            ->label('')
                            ->content(function (Forms\Get $get) {
                                if (!$get('member_id')) {
                                    return '';
                                }
                                $member = Member::find($get('member_id'));
                                if (!$member) return '';
                                
                                if (!$member->profile_picture) {
                                    $initials = strtoupper(substr($member->name ?? '', 0, 1));
                                    return new HtmlString(
                                        '<div style="width: 120px; height: 120px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; font-size: 48px; color: #6b7280; font-weight: bold;">' . 
                                        $initials . 
                                        '</div>'
                                    );
                                }
                                
                                return new HtmlString(
                                    '<img src="' . asset('storage/' . $member->profile_picture) . '" alt="' . $member->name . '" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid #e5e7eb;" />'
                                );
                            })
                            ->columnSpan(1),
                        
                        Grid::make(2)->schema([
                            Placeholder::make('member_name')
                                ->label('Member Name')
                                ->content(function (Forms\Get $get) {
                                    if (!$get('member_id')) return 'Not selected';
                                    $member = Member::find($get('member_id'));
                                    return $member?->name ?? 'N/A';
                                }),
                            
                            Placeholder::make('member_email')
                                ->label('Email')
                                ->content(function (Forms\Get $get) {
                                    if (!$get('member_id')) return 'Not selected';
                                    $member = Member::find($get('member_id'));
                                    return $member?->email ?? 'N/A';
                                }),
                            
                            Placeholder::make('member_phone')
                                ->label('Phone')
                                ->content(function (Forms\Get $get) {
                                    if (!$get('member_id')) return 'Not selected';
                                    $member = Member::find($get('member_id'));
                                    return $member?->phone ?? 'N/A';
                                }),
                            
                            // Placeholder::make('member_savings')
                            //     ->label('Total Savings')
                            //     ->content(function (Forms\Get $get) {
                            //         if (!$get('member_id')) return 'Not selected';
                            //         $member = Member::find($get('member_id'));
                            //         return 'KES ' . number_format($member?->total_savings ?? 0, 2);
                            //     }),
                            
                            Placeholder::make('national_id')
                                ->label('ID Number')
                                ->content(function (Forms\Get $get) {
                                    if (!$get('member_id')) return 'Not selected';
                                    $member = Member::find($get('member_id'));
                                    return $member?->national_id ?? 'N/A';
                                }),
                            
                            // Placeholder::make('member_status')
                            //     ->label('Member Status')
                            //     ->content(function (Forms\Get $get) {
                            //         if (!$get('member_id')) return 'Not selected';
                            //         $member = Member::find($get('member_id'));
                            //         return $member?->membership_status ?? 'N/A';
                            //     }),
                                // ->color(fn ($state) => $state === 'Active' ? 'success' : 'danger'),
                        ])->columnSpan(3),
                    ]),
                ])
                ->visible(fn (Forms\Get $get) => !empty($get('member_id')))
                ->collapsible()
                ->collapsed(false),

            // Enhanced Loan Details Section
            Section::make('Loan Details')
                ->schema([
                    Grid::make(2)->schema([
                        Placeholder::make('loan_summary')
                            ->label('Loan Summary')
                            ->content(function (Forms\Get $get) {
                                if (!$get('loan_id')) return 'Please select a loan above.';
                                
                                $loan = Loan::with('loanProduct')->find($get('loan_id'));
                                if (!$loan) return 'Loan not found.';
                                
                                $totalRepaid = $loan->total_repaid;
                                $remaining = $loan->remaining_balance;
                                $principal = $loan->principal_amount;
                                // Calculate progress based on actual repayment vs repayment amount
                                $repaymentAmount = $loan->repayment_amount ?? $loan->principal_amount;
                                $progress = $repaymentAmount > 0 ? ($totalRepaid / $repaymentAmount) * 100 : 0;
                                // dd($progress, $repaymentAmount, $totalRepaid);
                                $progress = min($progress, 100); // Cap at 100%
                                
                                return new HtmlString(
                                    '<div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <p class="text-sm font-medium text-gray-500">Loan Product</p>
                                                <p class="text-lg font-semibold">' . ($loan->loanProduct->name ?? 'N/A') . '</p>
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-500">Loan Number</p>
                                                <p class="text-lg font-semibold">' . $loan->loan_number . '</p>
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-3 gap-4 mt-4">
                                            <div class="text-center p-3 bg-blue-50 rounded-lg">
                                                <p class="text-sm font-medium text-blue-600">Principal</p>
                                                <p class="text-lg font-bold text-blue-800">KES ' . number_format($principal, 2) . '</p>
                                            </div>
                                            <div class="text-center p-3 bg-green-50 rounded-lg">
                                                <p class="text-sm font-medium text-green-600">Total Repaid</p>
                                                <p class="text-lg font-bold text-green-800">KES ' . number_format($totalRepaid, 2) . '</p>
                                            </div>
                                            <div class="text-center p-3 bg-red-50 rounded-lg">
                                                <p class="text-sm font-medium text-red-600">Remaining</p>
                                                <p class="text-lg font-bold text-red-800">KES ' . number_format($remaining, 2) . '</p>
                                            </div>
                                            <div class="text-center p-3 bg-red-50 rounded-lg">
                                                <p class="text-sm font-medium text-red-600">Repayment Progress</p>
                                                <p class="text-lg font-bold text-red-800">'.  number_format($progress, 2) . '% Complete</p>
                                            </div>
                                        </div>
                                        
                                       
                                    </div>'
                                );
                            })
                            ->columnSpan(1),

                        Placeholder::make('loan_breakdown')
                            ->label('Outstanding Balance Breakdown')
                            ->content(function (Forms\Get $get) {
                                if (!$get('loan_id')) return 'Please select a loan above.';
                                
                                $loan = Loan::find($get('loan_id'));
                                if (!$loan) return 'Loan not found.';
                                
                                $charges = $loan->getOutstandingLoanCharges();
                                $interest = $loan->getOutstandingInterest();
                                $principal = $loan->getOutstandingPrincipal();
                                $total = $charges + $interest + $principal;
                                
                                return new HtmlString(
                                    '<div class="space-y-3">
                                        <div class="flex justify-between items-center p-3 border-b">
                                            <span class="font-medium">Outstanding Principal:</span>
                                            <span class="font-bold text-blue-600">KES ' . number_format($principal, 2) . '</span>
                                        </div>
                                        <div class="flex justify-between items-center p-3 border-b">
                                            <span class="font-medium">Outstanding Interest:</span>
                                            <span class="font-bold text-yellow-600">KES ' . number_format($interest, 2) . '</span>
                                        </div>
                                        <div class="flex justify-between items-center p-3 border-b">
                                            <span class="font-medium">Outstanding Charges:</span>
                                            <span class="font-bold text-red-600">KES ' . number_format($charges, 2) . '</span>
                                        </div>
                                        <div class="flex justify-between items-center p-3  rounded-lg mt-2">
                                            <span class="font-bold text-lg">Total Outstanding:</span>
                                            <span class="font-bold text-lg text-gray-800">KES ' . number_format($total, 2) . '</span>
                                        </div>
                                    </div>'
                                );
                            })
                            ->columnSpan(1),
                    ]),
                    
                    // Loan Terms Information
                    Placeholder::make('loan_terms')
                        ->label('Loan Terms')
                        ->content(function (Forms\Get $get) {
                            if (!$get('loan_id')) return '';
                            
                            $loan = Loan::with('loanProduct')->find($get('loan_id'));
                            if (!$loan) return '';
                            
                            return new HtmlString(
                                '<div class="grid grid-cols-4 gap-4 p-4 rounded-lg">
                                    <div class="text-center">
                                        <p class="text-sm font-medium text-gray-500">Interest Rate</p>
                                        <p class="text-lg font-semibold">' . ($loan->interest_rate ?? 'N/A') . '%</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm font-medium text-gray-500">Duration</p>
                                        <p class="text-lg font-semibold">' . ($loan->loan_duration ?? 'N/A') . ' months</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm font-medium text-gray-500">Disbursed On</p>
                                        <p class="text-lg font-semibold">' . ($loan->release_date ? $loan->release_date->format('M d, Y') : 'N/A') . '</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm font-medium text-gray-500">Due Date</p>
                                        <p class="text-lg font-semibold">' . ($loan->due_at ? $loan->due_at->format('M d, Y') : 'N/A') . '</p>
                                    </div>
                                </div>'
                            );
                        })
                        ->columnSpanFull(),
                ])
                ->visible(fn (Forms\Get $get) => !empty($get('loan_id')))
                ->collapsible()
                ->collapsed(false),
        ];
    }

    protected function getFormModel(): string
    {
        return self::class;
    }

    public function submit()
    {
        $data = $this->form->getState();

        $res = $this->validateSubmission($data);

        if (!$res['success']) {
            return;
        }
        
        // Create the loan repayment record
        $repayment = LoanRepayment::create([
            'loan_id' => $data['loan_id'],
            'member_id' => $data['member_id'],
            'amount' => str_replace(',', '', $data['amount']),
            'repayment_date' => $data['repayment_date'],
            'payment_method' => $data['payment_method'],
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => Auth::id(),
        ]);

        // Create transactions for the repayment
        $this->createRepaymentTransactions($repayment);

        Log::info('Loan repayment recorded successfully', ['repayment' => $repayment->toArray()]);
        
        Notification::make()
            ->success()
            ->title('Repayment Recorded Successfully')
            ->body('The loan repayment has been recorded successfully.')
            ->send();
            
        // Reset the form after submission
        $this->form->fill();
    }

    /**
     * Create transactions for loan repayment with interest allocation
     */
    private function createRepaymentTransactions(LoanRepayment $repayment)
    {
        $loan = $repayment->loan;
        $amount = $repayment->amount;
        
        // Get account name based on payment method
        $accountName = $this->getAccountNameForPaymentMethod($repayment->payment_method);
        
        // Use the repayment allocation service
        $allocationService = new RepaymentAllocationService();
        $transactionData = $allocationService->createRepaymentTransactions(
            $loan, 
            (float) $amount, 
            $repayment->payment_method, 
            $accountName
        );
        
        // Create transactions
        foreach ($transactionData as $data) {
            Transaction::create(array_merge($data, [
                'repayment_id' => $repayment->id,
                'transaction_date' => $repayment->repayment_date,
            ]));
        }

        // Update loan status if fully repaid
        if ($loan->remaining_balance <= 0) {
            $loan->update(['status' => 'Fully Repaid']);
        }
    }
    
    /**
     * Get the appropriate account name based on payment method
     */
    private function getAccountNameForPaymentMethod(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'cash' => config('repayment_priority.accounts.cash'),
            'bank_transfer' => config('repayment_priority.accounts.bank'),
            'mobile_money' => config('repayment_priority.accounts.mobile_money'),
            'cheque' => config('repayment_priority.accounts.bank'),
            default => config('repayment_priority.accounts.bank'),
        };
    }

    private function validateSubmission($data) {
        $data = $this->form->getState();
        $loan = Loan::find($data['loan_id']);
        
        if (!$loan) {
            Notification::make()
                ->title('Invalid Loan')
                ->body('The selected loan was not found.')
                ->danger()
                ->send();
            
            return [
                'success' => false,
                'message' => 'Loan not found',
            ];
        }
        
        $currentAmountOwed = $loan->remaining_balance;
        $repaymentAmount = (float) str_replace(',', '', $data['amount']);
        
        if ($repaymentAmount > $currentAmountOwed) {
            Notification::make()
                ->title('Invalid Amount')
                ->body("The repayment amount cannot be greater than the current amount owed (KES " . number_format($currentAmountOwed, 2) . ").")
                ->danger()
                ->send();
            
            return [
                'success' => false,
                'message' => 'Repayment Amount is greater than the current amount owed',
            ];
        }
        
        if ($repaymentAmount <= 0) {
            Notification::make()
                ->title('Invalid Amount')
                ->body('The repayment amount must be greater than zero.')
                ->danger()
                ->send();
            
            return [
                'success' => false,
                'message' => 'Repayment amount must be greater than zero',
            ];
        }

        return [
            'success' => true,
            'message' => "Form validated successfully",
        ];
    }
}