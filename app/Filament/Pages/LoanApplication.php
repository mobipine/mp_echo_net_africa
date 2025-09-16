<?php

namespace App\Filament\Pages;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Filament\Support\RawJs;
use Carbon\Carbon;

class LoanApplication extends Page implements HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-plus';
    protected static string $view = 'filament.pages.loan-application';
    protected static ?string $title = 'Loan Application';
    protected static ?string $navigationLabel = 'Loan Application';
    protected static ?string $navigationGroup = 'Loan Management';

    // Form data array
    public ?array $data = [];
    
    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && Auth::user()->can('page_LoanApplication');
    }

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->can('page_LoanApplication'), 403);
        
        // Check if we're resuming an incomplete application
        if (request()->has('session_data')) {
            $sessionData = request('session_data');
            if (!is_array($sessionData)) {
                $sessionData = json_decode($sessionData, true);
            }
            if ($sessionData) {
                $this->data = $sessionData;
            }
        }
        
        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    Wizard\Step::make('Member Details')
                        ->icon('heroicon-o-user')
                        ->schema($this->getStepOneSchema()),
                    
                    Wizard\Step::make('Loan Particulars')
                        ->icon('heroicon-o-document-text')
                        ->schema($this->getStepTwoSchema()),
                    
                    Wizard\Step::make('Guarantors')
                        ->icon('heroicon-o-users')
                        ->schema($this->getStepThreeSchema()),
                    
                    Wizard\Step::make('Collaterals')
                        ->icon('heroicon-o-shield-check')
                        ->schema($this->getStepFourSchema()),
                ])
                ->nextAction(
                    fn (Action $action) => $action->action(function () {
                        // dd("here");
                        $this->saveSessionData();
                        // Let the wizard handle the step change
                    })
                )
                ->previousAction(
                    fn (Action $action) => $action->action(function () {
                        $this->saveSessionData();
                        // Let the wizard handle the step change
                    })
                )
                // ->submitAction(
                //     fn (Action $action) => $action
                //         ->label('Submit Application')
                //         ->action('submit')
                //         ->color('primary')
                // ),
                ->submitAction(
                    Action::make('submit')
                        ->label('Submit Application')
                        ->action('submit')
                        ->color('primary')
                ),
                
                Actions::make([
                    Action::make('save_draft')
                        ->label('Save as Draft')
                        ->action('saveDraft')
                        ->color('gray')
                        ->icon('heroicon-o-document-arrow-down'),
                        
                    Action::make('reset')
                        ->label('Reset Form')
                        ->action('resetForm')
                        ->color('danger')
                        ->icon('heroicon-o-trash')
                        ->requiresConfirmation()
                        ->modalHeading('Reset Form')
                        ->modalDescription('Are you sure you want to reset the form? All data will be lost.')
                        ->modalSubmitActionLabel('Yes, reset it'),
                ]),
            ])
            ->statePath('data');
    }

    protected function getStepOneSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('member_id')
                    ->label('Select Member')
                    ->options(Member::all()->pluck('name', 'id')->toArray())
                    ->live()
                    ->native(false)
                    ->searchable()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $this->fillMemberDetails($set, $state);
                        $this->updateLoanNumber($set, $state);
                        // Save immediately when member is selected
                        $this->dispatch('form-updated');
                    })
                    ->required(),
                    
                TextInput::make('name')
                    ->label('Name')
                    ->readOnly()
                    ->dehydrated(),
                    
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->readOnly()
                    ->dehydrated(),
                    
                TextInput::make('phone')
                    ->label('Phone')
                    ->required()
                    ->readOnly()
                    ->dehydrated(),
                    
                TextInput::make('national_id')
                    ->label('National ID')
                    ->required()
                    ->readOnly()
                    ->dehydrated(),
                    
                Select::make('gender')
                    ->label('Gender')
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                    ])
                    ->disabled()
                    ->dehydrated(),
                    
                Select::make('marital_status')
                    ->label('Marital Status')
                    ->options([
                        'single' => 'Single',
                        'married' => 'Married',
                    ])
                    ->disabled()
                    ->dehydrated(),
            ]),
        ];
    }

    protected function getStepTwoSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('loan_product_id')
                    ->label('Loan Product')
                    ->native(false)
                    ->options(LoanProduct::all()->pluck('name', 'id')->toArray())
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        if ($state) {
                            $loanProduct = LoanProduct::with('LoanProductAttributes')->find($state);
                            if ($loanProduct) {
                                $this->setLoanAttributes($set, $loanProduct);
                            }
                        }
                        $this->dispatch('form-updated');
                    })
                    ->required(),

                TextInput::make('loan_number')
                    ->label('Loan Number')
                    ->required()
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('interest_cycle')
                    ->label('Interest Cycle')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('loan_duration')
                    ->label('Loan Duration')
                    ->numeric()
                    ->placeholder('Enter Loan Duration')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                        $releaseDate = $get('release_date');
                        $cycle = $get('interest_cycle');
                        if ($releaseDate && $state && $cycle) {
                            $this->calculateDueDate($set, $releaseDate, $state, $cycle);
                        }
                        $this->dispatch('form-updated');
                    })
                    ->required(),

                TextInput::make('principal_amount')
                    ->label('Principal Amount')
                    ->placeholder('Enter requested loan amount')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                        $this->calculateLoanAmounts($set, $state, $get);
                        $this->dispatch('form-updated');
                    })
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),

                DatePicker::make('release_date')
                    ->label('Release Date')
                    ->live()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                        $duration = $get('loan_duration');
                        $cycle = $get('interest_cycle');
                        if ($duration && $cycle && $state) {
                            $this->calculateDueDate($set, $state, $duration, $cycle);
                        }
                        $this->dispatch('form-updated');
                    })
                    ->required(),

                DatePicker::make('due_at')
                    ->label('Due Date')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('repayment_amount')
                    ->label('Repayment Amount')
                    ->numeric()
                    ->default(0)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('interest_amount')
                    ->label('Interest Amount')
                    ->numeric()
                    ->default(0)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('max_loan_amount')
                    ->label('Max Loan Amount')
                    ->numeric()
                    ->default(0)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('loan_charges')
                    ->label('Loan Charges')
                    ->numeric()
                    ->default(0)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('interest_rate')
                    ->label('Interest Rate (%)')
                    ->numeric()
                    ->default(0)
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('interest_type')
                    ->label('Interest Type')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('interest_accrual_moment')
                    ->label('Interest Accrual Moment')
                    ->readOnly()
                    ->dehydrated(),

                Grid::make(1)->schema([
                    Textarea::make('loan_purpose')
                        ->label('Loan Purpose')
                        ->placeholder('Describe the purpose of this loan')
                        ->rows(3)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function () {
                            $this->dispatch('form-updated');
                        })
                        ->columnSpanFull(),
                ]),
            ])
        ];
    }

    protected function getStepThreeSchema(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('guarantor_name')
                    ->label('Guarantor Name')
                    ->placeholder('Enter guarantor name')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () {
                        $this->dispatch('form-updated');
                    }),
                    
                TextInput::make('guarantor_phone')
                    ->label('Guarantor Phone')
                    ->placeholder('Enter guarantor phone number')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () {
                        $this->dispatch('form-updated');
                    }),
                    
                TextInput::make('guarantor_id')
                    ->label('Guarantor ID Number')
                    ->placeholder('Enter guarantor ID number')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () {
                        $this->dispatch('form-updated');
                    }),
                    
                Grid::make(1)->schema([
                    Textarea::make('guarantor_address')
                        ->label('Guarantor Address')
                        ->placeholder('Enter guarantor address')
                        ->rows(3)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function () {
                            $this->dispatch('form-updated');
                        })
                        ->columnSpanFull(),
                ]),
            ]),
        ];
    }

    protected function getStepFourSchema(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('collateral_type')
                    ->label('Collateral Type')
                    ->placeholder('Enter collateral type')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () {
                        $this->dispatch('form-updated');
                    }),
                    
                TextInput::make('collateral_value')
                    ->label('Collateral Value')
                    ->numeric()
                    ->placeholder('Enter collateral value')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () {
                        $this->dispatch('form-updated');
                    }),
                    
                Grid::make(1)->schema([
                    Textarea::make('collateral_description')
                        ->label('Collateral Description')
                        ->placeholder('Enter collateral description')
                        ->rows(3)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function () {
                            $this->dispatch('form-updated');
                        })
                        ->columnSpanFull(),
                        
                    Textarea::make('additional_notes')
                        ->label('Additional Notes')
                        ->placeholder('Enter any additional notes')
                        ->rows(3)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function () {
                            $this->dispatch('form-updated');
                        })
                        ->columnSpanFull(),
                ]),
            ]),
        ];
    }

    // Add JavaScript listener for auto-saving
    protected function getListeners(): array
    {
        return [
            'form-updated' => 'saveSessionData',
        ];
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        
        try {
            // Validate required fields
            if (!isset($data['member_id']) || !isset($data['loan_product_id'])) {
                Notification::make()
                    ->title('Error')
                    ->body('Member and Loan Product are required.')
                    ->danger()
                    ->send();
                return;
            }

            // Check if we're updating an existing incomplete loan
            $existingLoan = Loan::where('member_id', $data['member_id'])
                ->where('loan_product_id', $data['loan_product_id'])
                ->whereNotNull('session_data')
                ->where('status', 'Pending Approval')
                ->first();

            $loanData = [
                'loan_number' => $data['loan_number'] ?? $this->generateLoanNumber($data['member_id']),
                'principal_amount' => (float) str_replace(',', '', $data['principal_amount'] ?? 0),
                'interest_rate' => (float) ($data['interest_rate'] ?? 0),
                'interest_amount' => (float) str_replace(',', '', $data['interest_amount'] ?? 0),
                'repayment_amount' => (float) str_replace(',', '', $data['repayment_amount'] ?? 0),
                'release_date' => $data['release_date'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'loan_duration' => (int) ($data['loan_duration'] ?? 0),
                'loan_purpose' => $data['loan_purpose'] ?? '',
                'guarantor_name' => $data['guarantor_name'] ?? '',
                'guarantor_phone' => $data['guarantor_phone'] ?? '',
                'guarantor_id' => $data['guarantor_id'] ?? '',
                'guarantor_address' => $data['guarantor_address'] ?? '',
                'collateral_type' => $data['collateral_type'] ?? '',
                'collateral_value' => (float) str_replace(',', '', $data['collateral_value'] ?? 0),
                'collateral_description' => $data['collateral_description'] ?? '',
                'additional_notes' => $data['additional_notes'] ?? '',
                // 'session_data' => null, // Clear session data as application is complete
                'is_completed' => true,
            ];

            if ($existingLoan) {
                // Update existing loan with complete data
                $existingLoan->update($loanData);
            } else {
                // Create new loan
                Loan::create(array_merge($loanData, [
                    'member_id' => $data['member_id'],
                    'loan_product_id' => $data['loan_product_id'],
                    'status' => 'Pending Approval',
                ]));
            }

            $this->resetForm();
            
            Notification::make()
                ->title('Success')
                ->body('Loan application submitted successfully!')
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Log::error('Loan application submission error: ' . $e->getMessage());
            
            Notification::make()
                ->title('Error')
                ->body('Failed to submit loan application. Please try again.')
                ->danger()
                ->send();
        }
    }

    public function saveDraft(): void
    {
        $this->saveSessionData();
        
        Notification::make()
            ->title('Draft Saved')
            ->body('Your loan application has been saved as a draft.')
            ->success()
            ->send();
    }

    public function fillMemberDetails(Forms\Set $set, $memberId): void
    {
        if ($memberId) {
            $member = Member::find($memberId);
            if ($member) {
                $set('name', $member->name);
                $set('email', $member->email);
                $set('phone', $member->phone);
                $set('national_id', $member->national_id);
                $set('gender', $member->gender);
                $set('marital_status', $member->marital_status);
            }
        }
    }

    public function updateLoanNumber(Forms\Set $set, $memberId): void
    {
        if ($memberId) {
            $loanNumber = $this->generateLoanNumber($memberId);
            $set('loan_number', $loanNumber);
        }
    }

    private function generateLoanNumber($memberId): string
    {
        $member = Member::find($memberId);
        $accountNumber = $member ? $member->account_number : 'ACC-0000';
        return 'LN' . str_pad(Loan::count() + 1, 6, '0', STR_PAD_LEFT) . ' - (' . $accountNumber . ')';
    }

    // public function setLoanAttributes(Forms\Set $set, $loanProduct): void
    // {
    //     if ($loanProduct && $loanProduct->LoanProductAttributes) {
    //         foreach ($loanProduct->LoanProductAttributes as $attribute) {
    //             $set($attribute->attribute_name, $attribute->attribute_value);
    //         }
    //     }
    // }

    public function setLoanAttributes(callable $set, $loanProduct)
    {
        //get the attributes to search for
        $attributes = $loanProduct->LoanProductAttributes;
        // dd($attributes);
        // dd($loanProduct, $attributes);

        $arranged_attributes = [];
        foreach ($attributes as $attribute) {
            $value = $attribute->value;
            //loop through the loan_attributes table and get their ids
            $attribute = \App\Models\LoanAttribute::where('id', $attribute->loan_attribute_id)->first();
            // dd($attribute);
            // dd($attributeId);
            $arranged_attributes[$attribute->slug] = [
                'name' => $attribute->name,
                'slug' => $attribute->slug,
                'id' => $attribute->id,
                'value' => $value ?? null
            ];
        }

        // dd($arranged_attributes);
        // $this->setLoanParticulars($set, $arranged_attributes);
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

    public function calculateLoanAmounts(Forms\Set $set, $principalAmount, Forms\Get $get): void
    {
        $principalAmount = (float) str_replace(',', '', $principalAmount);
        $interestRate = (float) ($get('interest_rate') ?? 0);
        $loanDuration = (int) ($get('loan_duration') ?? 1);
        
        if ($principalAmount > 0 && $interestRate > 0 && $loanDuration > 0) {
            $interestAmount = ($principalAmount * $interestRate * $loanDuration) / 100;
            $repaymentAmount = $principalAmount + $interestAmount;
            
            $set('interest_amount', number_format($interestAmount, 2, '.', ''));
            $set('repayment_amount', number_format($repaymentAmount, 2, '.', ''));
        }
    }

    public function calculateDueDate(Forms\Set $set, $releaseDate, $duration, $cycle): void
    {
        if ($releaseDate && $duration && $cycle) {
            try {
                $releaseDate = Carbon::parse($releaseDate);
                $duration = (int) $duration;
                
                switch (strtolower($cycle)) {
                    case 'daily':
                        $dueDate = $releaseDate->copy()->addDays($duration);
                        break;
                    case 'weekly':
                        $dueDate = $releaseDate->copy()->addWeeks($duration);
                        break;
                    case 'monthly':
                        $dueDate = $releaseDate->copy()->addMonths($duration);
                        break;
                    case 'yearly':
                        $dueDate = $releaseDate->copy()->addYears($duration);
                        break;
                    default:
                        $dueDate = $releaseDate->copy()->addMonths($duration);
                }
                
                $set('due_at', $dueDate->format('Y-m-d'));
            } catch (\Exception $e) {
                Log::error('Date calculation error: ' . $e->getMessage());
            }
        }
    }

    public function saveSessionData(): void
    {
        // Get current form state
        $formData = $this->form->getState();
        // dd($formData);
        
        if (isset($formData['member_id']) && isset($formData['loan_product_id'])) {
            try {
                $existingLoan = Loan::where('member_id', $formData['member_id'])
                    ->where('loan_product_id', $formData['loan_product_id'])
                    ->whereNotNull('session_data')
                    ->where('status', 'Pending Approval')
                    ->first();

                if ($existingLoan) {
                    $existingLoan->update(['session_data' => $formData]);
                } else {
                    Loan::create([
                        'member_id' => $formData['member_id'],
                        'loan_product_id' => $formData['loan_product_id'],
                        'status' => 'Pending Approval',
                        'session_data' => $formData,
                        'loan_number' => $this->generateLoanNumber($formData['member_id']),
                        'principal_amount' => 0.0,
                        'interest_rate' => 0.0,
                        'interest_amount' => 0.0,
                        'repayment_amount' => 0.0,
                    ]);
                }
                
                // Optional: Show a subtle notification that data was saved
                Notification::make()
                    ->title('Progress Saved')
                    ->body('Your progress has been automatically saved.')
                    ->success()
                    ->duration(2000)
                    ->send();
                    
            } catch (\Exception $e) {
                Log::error('Session data save error: ' . $e->getMessage());
            }
        }
    }

    public function resetForm(): void
    {
        $this->data = [];
        $this->form->fill([]);
        
        Notification::make()
            ->title('Form Reset')
            ->body('The form has been reset successfully.')
            ->info()
            ->send();
    }
}