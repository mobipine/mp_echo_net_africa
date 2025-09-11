<?php

namespace App\Filament\Pages;

use App\Models\ChartofAccounts;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\DebtorTransaction;
use App\Models\LoanAttribute;
use App\Models\LoanProduct;
use App\Models\LoanProductAttribute;
use App\Models\Member;
use Carbon\Carbon;
use Closure;
use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;



class LoanApplication extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static string $view = 'filament.pages.loan-application';
    protected static ?string $navigationGroup = 'Loan Management';

    public ?array $data = [];
    public $member_id;
    public $name;
    public $account_number;
    public $email;
    public $phone;
    public $national_id;
    public $gender;
    public $marital_status;
    public $loan_product_id;
    public $loan_number;
    public $status;
    public $principal_amount;
    public $interest_rate;
    public $interest_cycle;
    public $interest_type;
    public $repayment_amount;
    public $interest_amount;
    public $release_date;
    public $due_date;
    public $loan_duration;
    public $loan_purpose;
    public $max_loan_amount;
    public $loan_charges;
    public $interest_accrual_moment;
    public $loan_attributes;
    public $current_step = 1;
    public $loan_id = null;
    public $is_completed = false;

    public function __construct()
    {
        $this->loan_attributes = LoanAttribute::all()->pluck('slug')->toArray();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()->can('page_LoanApplication');
    }

    public function mount($loan_id = null): void
    {
        abort_unless(auth()->user()->can('page_LoanApplication'), 403);

        // If we have a loan_id parameter, we're continuing an existing application
        if ($loan_id) {
            $incompleteLoan = Loan::where('id', $loan_id)
                ->where('is_completed', false)
                ->first();

            if ($incompleteLoan) {
                $this->loan_id = $incompleteLoan->id;
                $this->data = $incompleteLoan->wizard_data ?? [];
                $this->current_step = $incompleteLoan->current_step ?? 1;
                $this->member_id = $incompleteLoan->member_id;

                // Fill member details if we have them
                if (isset($this->data['member_id'])) {
                    $this->fillMemberDetails2($this->data['member_id']);
                }

                $this->form->fill($this->data);
                return;
            }
        }

        $this->form->fill();
    }

    //do a function to return a form wizard with 4 steps
    protected function getFormSchema(): array
    {
        return [
            Wizard::make([
                Step::make('Member Details')
                    ->icon('heroicon-o-user')
                    ->beforeValidation(function () {})
                    // ->afterValidation(function () {})
                    ->afterValidation(function (Step $step) {
                        // Save progress after each step
                        $this->saveApplicationProgress($step->getId());
                    })
                    ->schema($this->stepOneSchema()),


                Step::make('Loan Particulars')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->beforeValidation(function () {})
                    // ->afterValidation(function () {})
                    ->afterValidation(function (Step $step) {
                        // Save progress after each step
                        $this->saveApplicationProgress($step->getId());
                    })
                    ->schema($this->stepTwoSchema()),

                Step::make('Loan Guarantors')
                    ->icon('heroicon-o-user-group')
                    ->beforeValidation(function () {})
                    // ->afterValidation(function () {})
                    ->afterValidation(function (Step $step) {
                        // Save progress after each step
                        $this->saveApplicationProgress($step->getId());
                    })
                    ->schema($this->stepThreeSchema()),

                Step::make('Loan Collaterals')
                    ->icon('heroicon-o-banknotes')
                    ->beforeValidation(function () {})
                    // ->afterValidation(function () {})
                    ->afterValidation(function (Step $step) {
                        // Save progress after each step
                        $this->saveApplicationProgress($step->getId());
                    })
                    ->schema($this->stepFourSchema()),

            ])
                ->persistStepInQueryString()
                // ->startStep($this->current_step)
                
                ->nextAction(
                    fn(Action $action) => $action->label('Next'),
                )
                ->previousAction(
                    fn(Action $action) => $action->label('Previous'),
                )
                ->submitAction($this->renderBtn())
        ];
    }

    public function fillMemberDetails(callable $set, $memberId)
    {
        if ($memberId) {
            $member = Member::find($memberId);
            if ($member) {
                $set('name', $member->name);
                $set('account_number', $member->account_number);
                $set('email', $member->email);
                $set('phone', $member->phone);
                $set('national_id', $member->national_id);
                $set('gender', $member->gender);
                $set('marital_status', $member->marital_status);

                // Also update the class properties
                $this->name = $member->name;
                $this->account_number = $member->account_number;
                $this->email = $member->email;
                $this->phone = $member->phone;
                $this->national_id = $member->national_id;
                $this->gender = $member->gender;
                $this->marital_status = $member->marital_status;
            }
        }
    }

    // Helper method to fill member details
    public function fillMemberDetails2($memberId)
    {
        if ($memberId) {
            $member = Member::find($memberId);
            if ($member) {
                $this->name = $member->name;
                $this->account_number = $member->account_number;
                $this->email = $member->email;
                $this->phone = $member->phone;
                $this->national_id = $member->national_id;
                $this->gender = $member->gender;
                $this->marital_status = $member->marital_status;
            }
        }
    }

    protected function getFormModel(): string
    {
        return self::class;
    }

    protected function saveApplicationProgress($currentStep){
        // dd('called', $currentStep);
        try {
            DB::beginTransaction();

            $data = $this->form->getState();
            $data['current_step'] = $currentStep;

            if ($this->loan_id) {
                // Update existing loan application
                $loan = Loan::find($this->loan_id);
                $loan->update([
                    'wizard_data' => $data,
                    'current_step' => $currentStep,
                ]);
            } else {
                // Create new loan application
                $loan = Loan::create([
                    'member_id' => $this->member_id,
                    'loan_product_id' => $this->loan_product_id,
                    'status' => 'Draft',
                    'wizard_data' => $data,
                    'current_step' => $currentStep,
                    'is_completed' => false,
                ]);
                $this->loan_id = $loan->id;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Notification::make()
                ->danger()
                ->title('Error saving application')
                ->body('There was an error saving your application progress.')
                ->send();
        }
    }

    public function submit()
    {
        try {
            DB::beginTransaction();

            // Get the loan application
            $loan = Loan::find($this->loan_id);

            // Update with final data
            $finalData = $this->form->getState();

            $loan->update([
                'principal_amount' => str_replace(',', '', $finalData['principal_amount']),
                'status' => 'Pending Approval',
                'due_at' => $finalData['due_date'],
                'repayment_amount' => str_replace(',', '', $finalData['repayment_amount']),
                'release_date' => Carbon::parse($finalData['release_date'])->format('Y-m-d'),
                'interest_amount' => str_replace(',', '', $finalData['interest_amount']),
                'interest_rate' => $finalData['interest_rate'],
                'loan_duration' => $finalData['loan_duration'],
                'loan_number' => $finalData['loan_number'],
                'loan_purpose' => $finalData['loan_purpose'],
                'repayment_schedule' => $this->generateRepaymentSchedule($finalData),
                'amortization_schedule' => $this->generateAmortizationSchedule($finalData),
                'is_completed' => true,
                'submitted_at' => now(),
                'wizard_data' => null, // Clear wizard data as it's now in proper columns
            ]);

            DB::commit();

            Notification::make()
                ->success()
                ->title('Loan Application Submitted Successfully')
                ->send();

            return redirect('admin/loans');
        } catch (\Exception $e) {
            DB::rollBack();
            Notification::make()
                ->danger()
                ->title('Error submitting application')
                ->body('There was an error submitting your application.')
                ->send();
        }
    }

    public function generateAmortizationSchedule($loanData)
    {
        $principal = (float) str_replace(',', '', $loanData['principal_amount']);
        $interestRate = (float) $loanData['interest_rate'] / 100;
        $loanDuration = (int) $loanData['loan_duration'];
        $releaseDate = Carbon::parse($loanData['release_date']);

        $schedule = [];
        $balance = $principal;

        // Determine payment frequency based on interest cycle
        $cycle = $loanData['interest_cycle'] ?? 'Monthly';
        $periodsPerYear = $this->getPeriodsPerYear($cycle);

        // Calculate periodic interest rate
        $periodicRate = $interestRate / $periodsPerYear;

        // Calculate payment amount (using annuity formula)
        $payment = $principal * ($periodicRate * pow(1 + $periodicRate, $loanDuration)) /
            (pow(1 + $periodicRate, $loanDuration) - 1);

        for ($i = 1; $i <= $loanDuration; $i++) {
            $interest = $balance * $periodicRate;
            $principalPayment = $payment - $interest;
            $balance -= $principalPayment;

            // Calculate due date based on cycle
            $dueDate = $this->calculateNextDueDate($releaseDate, $cycle, $i);

            $schedule[] = [
                'period' => $i,
                'due_date' => $dueDate->format('Y-m-d'),
                'payment' => round($payment, 2),
                'principal' => round($principalPayment, 2),
                'interest' => round($interest, 2),
                'balance' => round(max($balance, 0), 2),
            ];
        }

        return $schedule;
    }

    private function getPeriodsPerYear($cycle)
    {
        switch ($cycle) {
            case 'Daily':
                return 365;
            case 'Weekly':
                return 52;
            case 'Monthly':
                return 12;
            case 'Yearly':
                return 1;
            default:
                return 12;
        }
    }

    private function calculateNextDueDate($startDate, $cycle, $period)
    {
        switch ($cycle) {
            case 'Daily':
                return $startDate->copy()->addDays($period);
            case 'Weekly':
                return $startDate->copy()->addWeeks($period);
            case 'Monthly':
                return $startDate->copy()->addMonths($period);
            case 'Yearly':
                return $startDate->copy()->addYears($period);
            default:
                return $startDate->copy()->addMonths($period);
        }
    }

    public function renderBtn()
    {
        return new HtmlString(Blade::render(<<<BLADE
            <x-filament::button type="submit" size="sm">Submit</x-filament::button>
        BLADE));
    }

    public function stepOneSchema()
    {
        return [
            Grid::make(2)->schema([
                Select::make('member_id')
                    ->label('Select Member')
                    ->options(Member::all()->pluck('name', 'id')->toArray())
                    ->reactive()
                    ->native(false)
                    ->searchable()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Log::info('afterStateUpdated triggered with state: ' . $state);
                        $this->fillMemberDetails($set, $state);
                    }),
                TextInput::make('name')->readOnly()->dehydrated(),
                TextInput::make('account_number')->readOnly()->dehydrated(),
                TextInput::make('email')->email()->readOnly()->dehydrated(),
                TextInput::make('phone')->required()->readOnly()->dehydrated(),
                TextInput::make('national_id')->required()->readOnly()->dehydrated(),
                Select::make('gender')->options([
                    'male' => 'Male',
                    'female' => 'Female',
                ])->disabled()->dehydrated(),
                Select::make('marital_status')->options([
                    'single' => 'Single',
                    'married' => 'Married',
                ])->disabled()->dehydrated(),

            ]),
        ];
    }

    public function stepTwoSchema()
    {
        return [
            Grid::make(2)->schema([

                Select::make('loan_product_id')
                    ->label('Loan Product')
                    ->native(false)
                    ->options(LoanProduct::all()->pluck('name', 'id')->toArray())
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        $loanProduct = LoanProduct::find($state)->with('LoanProductAttributes')->first();
                        $this->getandsetLoanAttributes($set, $loanProduct);
                    })
                    ->required(),

                TextInput::make('loan_number')
                    ->label('Loan Number')
                    //set the default value to LN0001 where 0001 is the count of loans + 1 and append the selected member account number
                    ->default(fn() => 'LN' . str_pad(Loan::count() + 1, 6, '0', STR_PAD_LEFT) . '_' . $this->account_number)
                    ->required()
                    ->readOnly(),

                Forms\Components\TextInput::make('status')
                    ->default('Pending Approval')
                    ->readOnly(),

                Forms\Components\TextInput::make('interest_cycle')
                    ->label('Interest Cycle')
                    ->readOnly(),

                Forms\Components\TextInput::make('loan_duration')
                    ->label('Loan Duration')
                    ->numeric()
                    ->placeholder('Enter Loan Duration depending on the interest cycle')
                    // ->helperText('Enter Loan Duration depending on the interest cycle')
                    ->required(),

                Forms\Components\TextInput::make('principal_amount')
                    ->label('Principal Amount')
                    ->placeholder('Enter requested loan amount')
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // $set('repayment_amount', $state);
                        $this->calculateLoanAmounts($set, $state);
                    })
                    //make comma separated
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),

                Forms\Components\DatePicker::make('release_date')
                    ->label('Release Date')
                    ->reactive()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->timezone('Africa/Nairobi')
                    ->locale('en')
                    ->afterStateUpdated(function ($state, callable $set) {
                        // dd($state);
                        $this->calculateDueDate($set, $state);
                    })
                    ->required(),

                Forms\Components\DatePicker::make('due_date')
                    ->label('Due Date')
                    ->displayFormat('d/m/Y')
                    ->timezone('Africa/Nairobi')
                    ->locale('en')
                    ->required()
                    ->native(false)
                    // ->hidden()
                    ->readOnly(),

                Forms\Components\TextInput::make('repayment_amount')
                    ->label('Repayment Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),

                Forms\Components\TextInput::make('interest_amount')
                    ->label('Interest Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),


                Forms\Components\TextInput::make('max_loan_amount')
                    ->label('Max Loan Amount')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),

                Forms\Components\TextInput::make('loan_charges')
                    ->label('Loan Charges')
                    ->numeric()
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly(),

                Forms\Components\TextInput::make('interest_rate')
                    ->label('Interest Rate')
                    ->numeric()
                    ->required()
                    ->readOnly(),

                Forms\Components\TextInput::make('interest_type')
                    ->label('Interest Type')
                    ->required()
                    ->readOnly(),

                Forms\Components\TextInput::make('interest_accrual_moment')
                    ->label('Interest Accrual Moment')
                    ->required()
                    ->readOnly(),
            ])
        ];
    }

    public function stepThreeSchema()
    {
        return [
            // ...
        ];
    }

    public function stepFourSchema()
    {
        return [
            // ...
        ];
    }

    public function getandsetLoanAttributes(callable $set, $loanProduct)
    {
        //get the attributes to search for
        $attributes = $this->loan_attributes;
        // dd($attributes);

        $arranged_attributes = [];
        foreach ($attributes as $attribute) {
            //loop through the loan_attributes table and get their ids
            $attribute = LoanAttribute::where('slug', $attribute)->first();
            // dd($attributeId);
            $arranged_attributes[$attribute->slug] = [
                'name' => $attribute->name,
                'slug' => $attribute->slug,
                'id' => $attribute->id,
                'value' => $loanProduct->LoanProductAttributes->where('loan_attribute_id', $attribute->id)->first()->value ?? null
            ];
        }

        // dd($arranged_attributes);
        $this->setLoanParticulars($set, $arranged_attributes);
    }

    public function setLoanParticulars($set, $arranged_attributes)
    {
        foreach ($arranged_attributes as $attribute) {
            $set(
                $attribute['slug'],
                (int)($attribute['value']) > 0 ? number_format($attribute['value'], 2) : $attribute['value']
            );
        }
    }

    public function calculateLoanAmounts($set, $state)
    {
        //if principal amount is filled, calculate the repayment amount and interest amount and interest_type
        //get the interest cycle, loan duration and interest rate
        $interest_cycle = $this->interest_cycle; //daily, weekly, monthly, yearly
        $loan_duration = $this->loan_duration; //number of days, weeks, months, years
        $interest_rate = $this->interest_rate; //percentage
        $interest_type = $this->interest_type; //flat, reducing balance, simple interest
        $principal_amount = (int) str_replace(',', '', $state);

        if (!$state || !$this->interest_cycle || !$this->loan_duration || !$this->interest_rate || !$this->interest_type) {
            return;
        }

        $loan_duration = $this->formatLoanDuration($loan_duration, $interest_cycle);
        $interest_rate = $interest_rate / 100;

        //calculate the interest amount
        if ($interest_type == 'Flat') {
            $interest_amount = $principal_amount * $interest_rate;
        } else if ($interest_type == 'ReducingBalance') {
            // $interest_amount = $principal_amount * $interest_rate * $loan_duration['value'];
        } else if ($interest_type == 'Simple') {
            $interest_amount = $principal_amount * $interest_rate * $loan_duration['value'];
        }

        //calculate the repayment amount
        $repayment_amount = $principal_amount + $interest_amount;

        $set('repayment_amount', number_format($repayment_amount, 2));
        $set('interest_amount', number_format($interest_amount, 2));
    }

    public function formatLoanDuration($loan_duration, $interest_cycle)
    {
        // return $loan_duration['value'] . " " . $loan_duration['unit'];
        if ($interest_cycle == 'Daily') {
            $loan_duration = [
                'value' => $loan_duration,
                'unit' => 'days',
            ];
        } else if ($interest_cycle == 'Weekly') {
            $loan_duration = [
                'value' => $loan_duration,
                'unit' => 'weeks',
            ];
        } else if ($interest_cycle == 'Monthly') {
            $loan_duration = [
                'value' => $loan_duration,
                'unit' => 'months',
            ];
        } else if ($interest_cycle == 'Yearly') {
            $loan_duration = [
                'value' => $loan_duration,
                'unit' => 'years',
            ];
        }

        return $loan_duration;
    }

    public function calculateDueDate($set, $state)
    {
        $release_date = $state;


        $interest_cycle = $this->interest_cycle;
        $loan_duration = $this->loan_duration;

        $loan_duration = $this->formatLoanDuration($loan_duration, $interest_cycle);

        $due_date = Carbon::parse($release_date)->add((int)$loan_duration['value'], $loan_duration['unit']);
        // dd($release_date, $due_date);

        // $set('due_date', $due_date->format('d/m/Y'));
        $set('due_date', $due_date);
    }

    // public function submit()
    // {
    //     // dd($this->form->getState(), $this->due_date, Carbon::createFromFormat('d/m/Y', $this->due_date)->format('Y-m-d'), $this->due_date, $this->release_date);

    //     //create the following records
    //     //loan 1 record
    //     $loan = new Loan();
    //     // dd($this->form->getState());
    //     $loan->member_id = $this->member_id;
    //     $loan->loan_product_id = $this->loan_product_id;
    //     $loan->principal_amount = str_replace(',', '', $this->principal_amount);
    //     $loan->status = $this->status;
    //     // $loan->issued_at = null;
    //     //$this->due_date looks like '2023-10-01' and we need to convert it to Carbon format
    //     // $loan->due_at = Carbon::createFromFormat('d/m/Y', $this->due_date)->format('Y-m-d');
    //     $loan->due_at = $this->due_date;
    //     // $loan->due_date = Carbon::createFromFormat('Y-m-d', $this->due_date)->format('Y-m-d');
    //     $loan->repayment_amount = str_replace(',', '', $this->repayment_amount);
    //     $loan->release_date = Carbon::createFromFormat('Y-m-d H:i:s', $this->release_date)->format('Y-m-d');
    //     $loan->interest_amount = str_replace(',', '', $this->interest_amount);
    //     $loan->interest_rate = $this->interest_rate;
    //     $loan->loan_duration = $this->loan_duration;
    //     $loan->loan_number = $this->loan_number;
    //     $loan->loan_purpose = $this->loan_purpose;
    //     $loan->repayment_schedule = $this->generateRepaymentSchedule();
    //     $loan->save();


    //     //transactions table -> 2 records (double entry) dr loan_receivable account, cr bank
    //     $transaction = new Transaction();
    //     $transaction->chart_of_account_id = ChartofAccounts::where('name', 'Loans Receivable')->first()->id;
    //     $transaction->transaction_type  = "loan_issue";
    //     $transaction->dr_cr = "dr";
    //     $transaction->amount = str_replace(',', '', $this->principal_amount);
    //     $transaction->transaction_date = Carbon::createFromFormat('Y-m-d H:i:s', $this->release_date)->format('Y-m-d');
    //     $transaction->description = "Debit Loan Receivables Account for loan issued to member " . $this->member_id;
    //     $transaction->save();

    //     $transaction = new Transaction();
    //     $transaction->chart_of_account_id = ChartofAccounts::where('name', 'Bank')->first()->id;
    //     $transaction->transaction_type  = "loan_issue";
    //     $transaction->dr_cr = "cr";
    //     $transaction->amount = str_replace(',', '', $this->principal_amount);
    //     $transaction->transaction_date = Carbon::createFromFormat('Y-m-d H:i:s', $this->release_date)->format('Y-m-d');
    //     $transaction->description = "Credit Bank Account for Loan issued to member " . $this->member_id;
    //     $transaction->save();

    //     // debtors transactions table -> 1 record member_id(i.e the debtor) dr loan_receivable account 
    //     $debtor_transaction = new DebtorTransaction();
    //     $debtor_transaction->member_id = $this->member_id;
    //     $debtor_transaction->chart_of_account_id = ChartofAccounts::where('name', 'Loans Receivable')->first()->id;
    //     $debtor_transaction->transaction_type  = "loan_issue";
    //     $debtor_transaction->dr_cr = "dr";
    //     $debtor_transaction->amount = str_replace(',', '', $this->principal_amount);
    //     $debtor_transaction->transaction_date = Carbon::createFromFormat('Y-m-d H:i:s', $this->release_date)->format('Y-m-d');
    //     $debtor_transaction->description = "Debit Loan Receivables Account for loan issued to member " . $this->member_id;
    //     $debtor_transaction->save();

    //     Log::info('Loan Application submitted successfully', ['loan' => $loan->toArray()]);
    //     // Redirect or show a success message
    //     // $this->notify('success', 'Loan Application submitted successfully');
    //     Notification::make()
    //         ->success()
    //         ->title('Loan Application Submitted Successfully')
    //         ->send();
    //     // Optionally, you can redirect to a different page or reset the form
    //     //redirect to the loan resource page after submission
    //     $this->form->fill(); // Reset the form after submission
    //     return redirect('admin/loans');
    // }
}
