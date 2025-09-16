<?php

namespace App\Filament\Pages;

use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Member;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        // dd(Auth::user()->getPermissionsViaRoles()->pluck('name'));
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
            Grid::make(2)->schema([
                Select::make('member_id')
                    ->label('Select Member')
                    ->options(Member::all()->pluck('name', 'id')->toArray())
                    ->reactive()
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->afterStateUpdated(function ($state, callable $set) {
                        $this->member_id = $state;
                        $set('loan_id', null); // Reset loan selection when member changes
                    }),

                Select::make('loan_id')
                    ->label('Select Loan')
                    ->options(function () {
                        if (!$this->member_id) {
                            return [];
                        }
                        return Loan::where('member_id', $this->member_id)
                            ->where('status', 'Approved')
                            ->whereRaw('repayment_amount > COALESCE((SELECT SUM(amount) FROM loan_repayments WHERE loan_id = loans.id), 0)')
                            ->get()
                            ->mapWithKeys(function ($loan) {
                                $remaining = $loan->remaining_balance;
                                return [$loan->id => "{$loan->loan_number} - KES " . number_format($remaining, 2) . " remaining"];
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
                    ->reactive(),

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
                    ->rows(3),
            ]),
        ];
    }

    protected function getFormModel(): string
    {
        return self::class;
    }

    public function submit()
    {
        $data = $this->form->getState();
        
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
            ->body('The loan repayment has been recorded and transactions have been created.')
            ->send();
            
        // Reset the form after submission
        $this->form->fill();
    }

    /**
     * Create transactions for loan repayment
     */
    private function createRepaymentTransactions(LoanRepayment $repayment)
    {
        $loan = $repayment->loan;
        $amount = $repayment->amount;

        // Create simplified transaction records
        // Debit: Bank Account (money coming in)
        Transaction::create([
            'account_name' => 'Bank',
            'loan_id' => $loan->id,
            'member_id' => $repayment->member_id,
            'transaction_type' => 'loan_repayment',
            'dr_cr' => 'dr',
            'amount' => $amount,
            'transaction_date' => $repayment->repayment_date,
            'description' => "Loan repayment from member {$repayment->member->name}",
        ]);

        // Credit: Loans Receivable Account (reducing the receivable)
        Transaction::create([
            'account_name' => 'Loans Receivable',
            'loan_id' => $loan->id,
            'member_id' => $repayment->member_id,
            'transaction_type' => 'loan_repayment',
            'dr_cr' => 'cr',
            'amount' => $amount,
            'transaction_date' => $repayment->repayment_date,
            'description' => "Loan repayment from member {$repayment->member->name}",
        ]);

        // Update loan status if fully repaid
        if ($loan->remaining_balance <= 0) {
            $loan->update(['status' => 'Completed']);
        }
    }
}
