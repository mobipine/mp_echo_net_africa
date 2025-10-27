<?php

namespace App\Filament\Pages;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Group;
use App\Models\LoanAttribute;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
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

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && Auth::user()->can('page_LoanApplication');
    }

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->can('page_LoanApplication'), 403);

        if (request()->has('loan_id')) {
            $loanId = request('loan_id');
            $loan = Loan::find($loanId);

            if ($loan && $loan->session_data) {
                $this->data = $loan->session_data;
            }
        } elseif (request()->has('session_data')) {
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
                    Wizard\Step::make('Group & Member Selection')
                        ->icon('heroicon-o-user-group')
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
                        fn(Action $action) => $action->action(function () {
                            $this->saveSessionData();
                        })
                    )
                    ->previousAction(
                        fn(Action $action) => $action->action(function () {
                            $this->saveSessionData();
                        })
                    )
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
                Select::make('group_id')
                    ->label('Select Group')
                    ->options(Group::all()->pluck('name', 'id')->toArray())
                    ->live()
                    ->native(false)
                    ->searchable()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $set('member_id', null);
                        $set('name', null);
                        $set('email', null);
                        $set('phone', null);
                        $set('national_id', null);
                        $set('gender', null);
                        $set('marital_status', null);
                        $this->dispatch('form-updated');
                    })
                    ->required()
                    ->columnSpan(2),

                Select::make('member_id')
                    ->label('Select Member')
                    ->options(function (Forms\Get $get) {
                        $groupId = $get('group_id');
                        if (!$groupId) {
                            return [];
                        }
                        return Member::where('group_id', $groupId)
                            ->get()
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->live()
                    ->native(false)
                    ->searchable()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $this->fillMemberDetails($set, $state);
                        $this->updateLoanNumber($set, $state);
                        $this->dispatch('form-updated');
                    })
                    ->required()
                    ->disabled(fn(Forms\Get $get) => !$get('group_id'))
                    ->columnSpan(2),
            ]),

            Grid::make(2)->schema([
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

            // Hidden field to store guarantors_required attribute
            Forms\Components\Hidden::make('guarantors_required')
                ->default(fn(Forms\Get $get) => $this->checkGuarantorsRequired($get)),

            // Hidden field to store all_members_required attribute
            Forms\Components\Hidden::make('all_members_guarantee')
                ->default(fn(Forms\Get $get) => $this->checkAllMembersGuarantee($get)),

            // Show message if guarantors not required
            Placeholder::make('no_guarantors')
                ->label('')
                ->content('✓ No guarantors are required for this loan product.')
                ->visible(fn(Forms\Get $get) => !$this->checkGuarantorsRequired($get)),

            // All Members Guarantee Section
            Section::make('All Group Members as Guarantors')
                ->description('All group members must guarantee this loan. The total guaranteed amount must equal the loan amount.')
                ->visible(
                    fn(Forms\Get $get) =>
                    $this->checkGuarantorsRequired($get) &&
                        $this->checkAllMembersGuarantee($get)
                )
                ->schema([
                    Placeholder::make('guarantee_summary')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $principalAmount = (float) str_replace(',', '', $get('principal_amount') ?? 0);
                            $guarantors = $get('all_member_guarantors') ?? [];
                            $totalGuaranteed = collect($guarantors)->sum(fn($g) => (float) str_replace(',', '', $g['amount'] ?? 0));
                            $remaining = $principalAmount - $totalGuaranteed;

                            $status = $remaining == 0 ? '✓' : '⚠';
                            $color = $remaining == 0 ? 'text-success-600' : 'text-warning-600';

                            return new \Illuminate\Support\HtmlString(
                                "<div class='p-4 rounded-lg bg-gray-50 dark:bg-gray-800'>
                                            <div class='flex justify-between items-center'>
                                                <span class='font-semibold'>Loan Amount:</span>
                                                <span class='text-lg font-bold'>" . number_format($principalAmount, 2) . "</span>
                                            </div>
                                            <div class='flex justify-between items-center mt-2'>
                                                <span class='font-semibold'>Total Guaranteed:</span>
                                                <span class='text-lg font-bold'>" . number_format($totalGuaranteed, 2) . "</span>
                                            </div>
                                            <div class='flex justify-between items-center mt-2 pt-2 border-t border-gray-200 dark:border-gray-700'>
                                                <span class='font-semibold'>Remaining:</span>
                                                <span class='text-lg font-bold {$color}'>{$status} " . number_format($remaining, 2) . "</span>
                                            </div>
                                        </div>"
                            );
                        }),

                    Repeater::make('all_member_guarantors')
                        ->label('')
                        ->schema([
                            Grid::make(3)->schema([
                                Select::make('member_id')
                                    ->label('Member')
                                    ->options(function (Forms\Get $get) {
                                        $groupId = $get('../../group_id');
                                        $currentMemberId = $get('../../member_id');
                                        if (!$groupId) {
                                            return [];
                                        }
                                        return Member::where('group_id', $groupId)
                                            ->where('id', '!=', $currentMemberId)
                                            ->get()
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->disabled()
                                    ->dehydrated(),

                                TextInput::make('name')
                                    ->label('Member Name')
                                    ->readOnly()
                                    ->dehydrated(),

                                TextInput::make('amount')
                                    ->label('Guaranteed Amount')
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function () {
                                        $this->dispatch('form-updated');
                                    })
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->helperText('Enter the amount this member will guarantee'),
                            ]),
                        ])
                        ->default(function (Forms\Get $get) {
                            return $this->getDefaultAllMemberGuarantors($get);
                        })
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columnSpanFull(),
                ]),

            // Selected Members Guarantee Section
            Section::make('Select Guarantors')
                ->description('Choose one or more group members to guarantee this loan. The total guaranteed amount must equal the loan amount.')
                ->visible(
                    fn(Forms\Get $get) =>
                    $this->checkGuarantorsRequired($get) &&
                        !$this->checkAllMembersGuarantee($get)
                )
                ->schema([
                    Placeholder::make('selected_guarantee_summary')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $principalAmount = (float) str_replace(',', '', $get('principal_amount') ?? 0);
                            $guarantors = $get('selected_guarantors') ?? [];
                            $totalGuaranteed = collect($guarantors)->sum(fn($g) => (float) str_replace(',', '', $g['amount'] ?? 0));
                            $remaining = $principalAmount - $totalGuaranteed;
                            $count = count($guarantors);

                            $status = $remaining == 0 && $count > 0 ? '✓' : '⚠';
                            $color = $remaining == 0 && $count > 0 ? 'text-success-600' : 'text-warning-600';

                            return new \Illuminate\Support\HtmlString(
                                "<div class='p-4 rounded-lg bg-gray-50 dark:bg-gray-800'>
                                            <div class='flex justify-between items-center'>
                                                <span class='font-semibold'>Loan Amount:</span>
                                                <span class='text-lg font-bold'>" . number_format($principalAmount, 2) . "</span>
                                            </div>
                                            <div class='flex justify-between items-center mt-2'>
                                                <span class='font-semibold'>Guarantors Selected:</span>
                                                <span class='text-lg font-bold'>" . $count . "</span>
                                            </div>
                                            <div class='flex justify-between items-center mt-2'>
                                                <span class='font-semibold'>Total Guaranteed:</span>
                                                <span class='text-lg font-bold'>" . number_format($totalGuaranteed, 2) . "</span>
                                            </div>
                                            <div class='flex justify-between items-center mt-2 pt-2 border-t border-gray-200 dark:border-gray-700'>
                                                <span class='font-semibold'>Remaining:</span>
                                                <span class='text-lg font-bold {$color}'>{$status} " . number_format($remaining, 2) . "</span>
                                            </div>
                                        </div>"
                            );
                        }),

                    Repeater::make('selected_guarantors')
                        ->label('Add Guarantors')
                        ->schema([
                            Grid::make(3)->schema([
                                Select::make('member_id')
                                    ->label('Select Member')
                                    ->options(function (Forms\Get $get) {
                                        $groupId = $get('../../group_id');
                                        $currentMemberId = $get('../../member_id');
                                        $selectedGuarantors = collect($get('../../selected_guarantors') ?? [])
                                            ->pluck('member_id')
                                            ->filter()
                                            ->toArray();

                                        if (!$groupId) {
                                            return [];
                                        }

                                        return Member::where('group_id', $groupId)
                                            ->where('id', '!=', $currentMemberId)
                                            ->whereNotIn('id', $selectedGuarantors)
                                            ->get()
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        if ($state) {
                                            $member = Member::find($state);
                                            if ($member) {
                                                $set('name', $member->name);
                                                $set('phone', $member->phone);
                                                $set('national_id', $member->national_id);
                                            }
                                        }
                                        $this->dispatch('form-updated');
                                    })
                                    ->searchable()
                                    ->native(false),

                                TextInput::make('name')
                                    ->label('Member Name')
                                    ->readOnly()
                                    ->dehydrated(),

                                TextInput::make('amount')
                                    ->label('Guaranteed Amount')
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function () {
                                        $this->dispatch('form-updated');
                                    })
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->helperText('Enter the amount this member will guarantee'),

                                Forms\Components\Hidden::make('phone'),
                                Forms\Components\Hidden::make('national_id'),
                            ]),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel('Add Guarantor')
                        ->reorderable(false)
                        ->columnSpanFull(),
                ]),

        ];
    }

    protected function getStepFourSchema(): array
    {
        return [

            Grid::make(2)->schema([
                TextInput::make('collateral_type')
                    ->label('Collateral Type')
                    ->placeholder('e.g., Property, Vehicle, Equipment')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () {
                        $this->dispatch('form-updated');
                    }),

                TextInput::make('collateral_value')
                    ->label('Collateral Value')
                    ->numeric()
                    ->placeholder('Enter estimated collateral value')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () {
                        $this->dispatch('form-updated');
                    }),

                Grid::make(1)->schema([
                    Textarea::make('collateral_description')
                        ->label('Collateral Description')
                        ->placeholder('Provide detailed description of the collateral')
                        ->rows(3)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function () {
                            $this->dispatch('form-updated');
                        })
                        ->columnSpanFull(),

                    Textarea::make('additional_notes')
                        ->label('Additional Notes')
                        ->placeholder('Any additional information about the loan application')
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

    protected function getListeners(): array
    {
        return [
            'form-updated' => 'saveSessionData',
        ];
    }

    // Helper methods for guarantor logic
    protected function checkGuarantorsRequired(Forms\Get $get): bool
    {
        $loanProductId = $get('loan_product_id');
        if (!$loanProductId) {
            return false;
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return false;
        }

        //get the loan attribute is_guarantors_required
        $guarantorsAttr = LoanAttribute::where('slug', 'is_guarantors_required')->first();
        if (!$guarantorsAttr) {
            return false;
        }

        $guarantorsAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $guarantorsAttr->id)
            ->first()
            ->value;

        // dd($guarantorsAttr, $loanProductId, $loanProduct->LoanProductAttributes);

        return $guarantorsAttr ? (bool) $guarantorsAttr : false;
    }

    protected function checkAllMembersGuarantee(Forms\Get $get): bool
    {
        $loanProductId = $get('loan_product_id');
        if (!$loanProductId) {
            return false;
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return false;
        }

        $allMembersAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute.slug', 'all_members_guarantee')
            ->first();

        return $allMembersAttr ? (bool) $allMembersAttr->value : false;
    }

    protected function getDefaultAllMemberGuarantors(Forms\Get $get): array
    {
        $groupId = $get('group_id');
        $currentMemberId = $get('member_id');
        $principalAmount = (float) str_replace(',', '', $get('principal_amount') ?? 0);

        if (!$groupId || !$currentMemberId) {
            return [];
        }

        $members = Member::where('group_id', $groupId)
            ->where('id', '!=', $currentMemberId)
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        $equalAmount = $principalAmount / $members->count();

        return $members->map(function ($member) use ($equalAmount) {
            return [
                'member_id' => $member->id,
                'name' => $member->name,
                'amount' => number_format($equalAmount, 2, '.', ''),
            ];
        })->toArray();
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

            // Validate guarantors if required
            $guarantorsRequired = $this->isGuarantorsRequired($data);
            if ($guarantorsRequired) {
                $isValid = $this->validateGuarantors($data);
                if (!$isValid) {
                    return;
                }
            }

            // Prepare guarantor data
            $guarantorData = $this->prepareGuarantorData($data);

            $existingLoan = Loan::where('member_id', $data['member_id'])
                ->where('loan_product_id', $data['loan_product_id'])
                ->where('status', 'Incomplete Application')
                ->first();

            $loanData = [
                'loan_number' => $data['loan_number'] ?? $this->generateLoanNumber($data['member_id']),
                'principal_amount' => (float) str_replace(',', '', $data['principal_amount'] ?? 0),
                'interest_rate' => (float) ($data['interest_rate'] ?? 0),
                'interest_amount' => 0,
                'repayment_amount' => (float) str_replace(',', '', $data['repayment_amount'] ?? 0),
                'release_date' => $data['release_date'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'loan_duration' => (int) ($data['loan_duration'] ?? 0),
                'loan_purpose' => $data['loan_purpose'] ?? '',
                'guarantor_data' => $guarantorData,
                'collateral_type' => $data['collateral_type'] ?? '',
                'collateral_value' => (float) str_replace(',', '', $data['collateral_value'] ?? 0),
                'collateral_description' => $data['collateral_description'] ?? '',
                'additional_notes' => $data['additional_notes'] ?? '',
                'status' => 'Pending Approval',
                'session_data' => null,
                'is_completed' => 1,
            ];

            if ($existingLoan) {
                $existingLoan->update($loanData);
                $loan = $existingLoan;
            } else {
                $loan = Loan::create(array_merge($loanData, [
                    'member_id' => $data['member_id'],
                    'loan_product_id' => $data['loan_product_id'],
                ]));
            }

            $this->resetForm();

            Notification::make()
                ->title('Success')
                ->body('Loan application submitted successfully! Redirecting to loans list...')
                ->success()
                ->send();

            $this->redirect(route('filament.admin.resources.loans.index'));
        } catch (\Exception $e) {
            Log::error('Loan application submission error: ' . $e->getMessage());

            Notification::make()
                ->title('Error')
                ->body('Failed to submit loan application. Please try again.')
                ->danger()
                ->send();
        }
    }

    protected function validateGuarantors(array $data): bool
    {
        $principalAmount = (float) str_replace(',', '', $data['principal_amount'] ?? 0);
        $allMembersGuarantee = $this->isAllMembersGuarantee($data);

        if ($allMembersGuarantee) {
            $guarantors = $data['all_member_guarantors'] ?? [];
            $totalGuaranteed = collect($guarantors)->sum(fn($g) => (float) str_replace(',', '', $g['amount'] ?? 0));

            if (abs($totalGuaranteed - $principalAmount) > 0.01) {
                Notification::make()
                    ->title('Validation Error')
                    ->body("Total guaranteed amount (" . number_format($totalGuaranteed, 2) . ") must equal the loan amount (" . number_format($principalAmount, 2) . ").")
                    ->danger()
                    ->send();
                return false;
            }
        } else {
            $guarantors = $data['selected_guarantors'] ?? [];

            if (empty($guarantors)) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('At least one guarantor is required for this loan product.')
                    ->danger()
                    ->send();
                return false;
            }

            $totalGuaranteed = collect($guarantors)->sum(fn($g) => (float) str_replace(',', '', $g['amount'] ?? 0));

            if (abs($totalGuaranteed - $principalAmount) > 0.01) {
                Notification::make()
                    ->title('Validation Error')
                    ->body("Total guaranteed amount (" . number_format($totalGuaranteed, 2) . ") must equal the loan amount (" . number_format($principalAmount, 2) . ").")
                    ->danger()
                    ->send();
                return false;
            }
        }

        return true;
    }

    protected function isGuarantorsRequired(array $data): bool
    {
        $loanProductId = $data['loan_product_id'] ?? null;
        if (!$loanProductId) {
            return false;
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return false;
        }

        foreach ($loanProduct->LoanProductAttributes as $attr) {
            $loanAttr = \App\Models\LoanAttribute::find($attr->loan_attribute_id);
            if ($loanAttr && $loanAttr->slug === 'guarantors_required') {
                return (bool) $attr->value;
            }
        }

        return false;
    }

    protected function isAllMembersGuarantee(array $data): bool
    {
        $loanProductId = $data['loan_product_id'] ?? null;
        if (!$loanProductId) {
            return false;
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return false;
        }

        foreach ($loanProduct->LoanProductAttributes as $attr) {
            $loanAttr = \App\Models\LoanAttribute::find($attr->loan_attribute_id);
            if ($loanAttr && $loanAttr->slug === 'all_members_guarantee') {
                return (bool) $attr->value;
            }
        }

        return false;
    }

    protected function prepareGuarantorData(array $data): ?array
    {
        if (!$this->isGuarantorsRequired($data)) {
            return null;
        }

        $allMembersGuarantee = $this->isAllMembersGuarantee($data);

        if ($allMembersGuarantee) {
            return [
                'type' => 'all_members',
                'guarantors' => $data['all_member_guarantors'] ?? [],
            ];
        } else {
            return [
                'type' => 'selected_members',
                'guarantors' => $data['selected_guarantors'] ?? [],
            ];
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

    public function setLoanAttributes(callable $set, $loanProduct)
    {
        $attributes = $loanProduct->LoanProductAttributes;
        $arranged_attributes = [];

        foreach ($attributes as $attribute) {
            $value = $attribute->value;
            $loanAttribute = \App\Models\LoanAttribute::where('id', $attribute->loan_attribute_id)->first();

            $arranged_attributes[$loanAttribute->slug] = [
                'name' => $loanAttribute->name,
                'slug' => $loanAttribute->slug,
                'id' => $loanAttribute->id,
                'value' => $value ?? null
            ];
        }

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
        } elseif ($interestRate == 0) {
            $repaymentAmount = $principalAmount;
            $set('interest_amount', number_format(0, 2, '.', ''));
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
        $formData = $this->form->getState() ?? [];

        if (isset($formData['member_id']) && isset($formData['loan_product_id'])) {
            try {
                $existingLoan = Loan::where('member_id', $formData['member_id'])
                    ->where('loan_product_id', $formData['loan_product_id'])
                    ->where('status', 'Incomplete Application')
                    ->first();

                if ($existingLoan) {
                    $existingLoan->update(['session_data' => $formData]);
                } else {
                    Loan::create([
                        'member_id' => $formData['member_id'],
                        'loan_product_id' => $formData['loan_product_id'],
                        'status' => 'Incomplete Application',
                        'session_data' => $formData,
                        'loan_number' => $this->generateLoanNumber($formData['member_id']),
                        'principal_amount' => 0.0,
                        'interest_rate' => 0.0,
                        'interest_amount' => 0.0,
                        'repayment_amount' => 0.0,
                        'is_completed' => 0,
                    ]);
                }

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
