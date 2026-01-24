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
use Filament\Support\Exceptions\Halt;
use Carbon\Carbon;

class LoanApplication extends Page implements HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-plus';
    protected static string $view = 'filament.pages.loan-application';
    protected static ?string $title = 'Loan Application';
    protected static ?string $navigationLabel = 'Loan Application';
    protected static ?string $navigationGroup = 'Loan Management';

    public bool $showKycModal = false;
    public array $missingKycDocs = [];
    public ?int $kycMemberId = null;

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
            ->disabled(fn() => $this->showKycModal)
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
                        fn(Action $action) => $action
                            ->disabled(fn() => $this->showKycModal)
                            ->action(function (Forms\Get $get) {
                                // Prevent progression if KYC modal is showing
                                if ($this->showKycModal) {
                                    throw new Halt();
                                }

                                // Check KYC documents before allowing progression from step 2
                                $currentStep = $this->form->getWizardCurrentStep();
                                if ($currentStep === 1) { // Step 2 (0-indexed)
                                    $memberId = $get('member_id');
                                    $loanProductId = $get('loan_product_id');

                                    if ($memberId && $loanProductId) {
                                        $member = Member::find($memberId);
                                        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);

                                        if ($member && $loanProduct) {
                                            // Get attachments_required attribute
                                            $attachmentsAttr = LoanAttribute::where('slug', 'attachments_required')->first();
                                            if ($attachmentsAttr) {
                                                $loanProductAttr = $loanProduct->LoanProductAttributes
                                                    ->where('loan_attribute_id', $attachmentsAttr->id)
                                                    ->first();

                                                if ($loanProductAttr && $loanProductAttr->value) {
                                                    $requiredDocTypes = json_decode($loanProductAttr->value, true);
                                                    if (is_array($requiredDocTypes) && !empty($requiredDocTypes)) {
                                                        $memberDocTypes = $member->kycDocuments()->pluck('document_type')->toArray();
                                                        $missingDocs = array_diff($requiredDocTypes, $memberDocTypes);

                                                        if (!empty($missingDocs)) {
                                                            // Prevent wizard progression
                                                            throw new Halt();
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }

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
                        // $this->dispatch('form-updated');
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
                        // Get members that belong to the selected group (via many-to-many)
                        return Member::whereHas('groups', function ($query) use ($groupId) {
                            $query->where('groups.id', $groupId);
                        })
                            ->get()
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->live()
                    ->native(false)
                    ->searchable()
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                        $this->fillMemberDetails($set, $state);
                        $this->updateLoanNumber($set, $state);
                        // Update max loan amount when member is selected (group might have changed)
                        $maxLoanAmount = $this->getMaxLoanAmount($set, $get);
                        if ($maxLoanAmount !== null) {
                            $set('max_loan_amount', number_format($maxLoanAmount, 2));
                        }
                        // Check KYC documents when member is selected
                        // $this->checkKycDocuments($get, $set);
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
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                        if ($state) {
                            // Check KYC documents immediately when loan product is selected
                            $this->checkKycDocumentsAndNotify($get, $set);

                            // dd($state, $get("all_member_guarantors"));
                            $loanProduct = LoanProduct::with('LoanProductAttributes')->find($state);
                            if ($loanProduct) {
                                $this->setLoanAttributes($set, $loanProduct, $get);
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

            // // All Members Guarantee Section - All group members act as guarantors
            // Section::make('All Group Members as Guarantors')
            //     ->description('All group members must guarantee this loan. The principal amount is shared equally among all members.')
            //     ->visible(
            //         fn(Forms\Get $get) =>
            //         $this->checkGuarantorsRequired($get)
            //     )
            //     ->schema([
                    Placeholder::make('guarantee_summary')
                        ->label('')
                        ->content(function (Forms\Get $get, Forms\Set $set) {
                            $all_member_guarantors = $this->getDefaultAllMemberGuarantors($get);
                            // dd($all_member_guarantors);
                            $set('all_member_guarantors', $all_member_guarantors);

                            $principalAmount = (float) str_replace(',', '', $get('principal_amount') ?? 0);
                            $guarantors = $get('all_member_guarantors') ?? [];
                            $totalGuaranteed = collect($guarantors)->sum(fn($g) => (float) str_replace(',', '', $g['amount'] ?? 0));
                            $remaining = $principalAmount - $totalGuaranteed;
                            $count = count($guarantors);

                            $isValid = abs($remaining) < 0.01 && $count > 0;
                            $status = $isValid ? '✓ Complete' : '⚠ Incomplete';
                            $bgColor = $isValid ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800';
                            $textColor = $isValid ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400';

                            // dd($all_member_guarantors);

                            return new \Illuminate\Support\HtmlString(
                                "<div class='p-4 rounded-lg border {$bgColor}'>
                                            <div class='flex justify-between items-center mb-3'>
                                                <span class='font-semibold text-sm'>Loan Amount:</span>
                                                <span class='text-lg font-bold'>KSh " . number_format($principalAmount, 2) . "</span>
                                            </div>
                                            <div class='flex justify-between items-center mb-3'>
                                                <span class='font-semibold text-sm'>Number of Guarantors:</span>
                                                <span class='text-lg font-bold'>" . count($all_member_guarantors) . " members</span>
                                            </div>
                                            <div class='flex justify-between items-center mb-3'>
                                                <span class='font-semibold text-sm'>Total Guaranteed:</span>
                                                <span class='text-lg font-bold'>KSh " . number_format($totalGuaranteed, 2) . "</span>
                                            </div>
                                            <div class='flex justify-between items-center pt-3 border-t border-gray-300 dark:border-gray-600'>
                                                <span class='font-semibold text-sm'>Status:</span>
                                                <span class='font-bold {$textColor}'>{$status}</span>
                                            </div>
                                            <div class='flex justify-between items-center mt-2'>
                                                <span class='font-semibold text-sm'>" . ($remaining > 0 ? 'Remaining to assign:' : 'Amount over-allocated:') . "</span>
                                                <span class='text-lg font-bold {$textColor}'>KSh " . number_format(abs($remaining), 2) . "</span>
                                            </div>
                                        </div>"
                            );
                        }),

                    Repeater::make('all_member_guarantors')
                        ->label('Group Members (All members are guarantors)')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('member_id')
                                    ->label('Member ID')
                                    ->disabled()
                                    ->dehydrated(),

                                TextInput::make('name')
                                    ->label('Member Name')
                                    ->readOnly()
                                    ->disabled()
                                    ->dehydrated(),

                                TextInput::make('amount')
                                    ->label('Guaranteed Amount')
                                    ->numeric()
                                    ->readOnly()
                                    ->disabled()
                                    ->dehydrated()
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->helperText('Amount is automatically calculated and shared equally'),
                            ]),
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columnSpanFull(),
                // ]),



        ];
    }

    protected function getStepFourSchema(): array
    {
        return [
            ...$this->getCollateralAttachmentsSchema(),

            Placeholder::make('no_collaterals')
                ->label('')
                ->content('✓ No collaterals are required for this loan product.')
                ->visible(fn(Forms\Get $get) => !$this->checkCollateralsRequired($get)),
        ];
    }

    protected function getCollateralAttachmentsSchema(): array
    {
        // Return fields directly without any wrapper
        // The fields will be dynamically generated based on loan product requirements
        return [
            Forms\Components\Group::make()
                ->schema(function (Forms\Get $get) {
                    return $this->buildCollateralFields($get);
                })
                ->visible(fn(Forms\Get $get) => $this->checkCollateralsRequired($get))
                ->columnSpanFull(),
        ];
    }

    protected function buildCollateralFields(Forms\Get $get): array
    {
        if (!$this->checkCollateralsRequired($get)) {
            return [];
        }

        $loanProductId = $get('loan_product_id');
        if (!$loanProductId) {
            return [];
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return [];
        }

        // Get collateral_attachments_required attribute
        $collateralAttr = LoanAttribute::where('slug', 'collateral_attachments_required')->first();
        if (!$collateralAttr) {
            return [];
        }

        $loanProductAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $collateralAttr->id)
            ->first();

        if (!$loanProductAttr || !$loanProductAttr->value) {
            return [];
        }

        $requiredDocTypes = explode(',', $loanProductAttr->value);
        if (!is_array($requiredDocTypes) || empty($requiredDocTypes)) {
            return [];
        }

        // Get document type names from DocsMeta
        $docTypes = \App\Models\DocsMeta::whereIn('id', $requiredDocTypes)->get();

        $fields = [];
        foreach ($docTypes as $docType) {
            $fields[] = Forms\Components\FileUpload::make('collateral_attachment_' . $docType->id)
                ->label($docType->name)
                ->directory('loan-collaterals')
                ->visibility('public')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'])
                ->maxSize(5120) // 5MB
                ->required()
                ->helperText('Upload ' . $docType->name . ' document (Required)')
                ->dehydrated()
                ->columnSpanFull();
        }

        return $fields;
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
            ?->value;

        // dd($guarantorsAttr, $loanProductId, $loanProduct->LoanProductAttributes);

        return $guarantorsAttr ? (bool) $guarantorsAttr : false;
    }

    protected function checkAllMembersGuarantee(Forms\Get $get): bool
    {
        // Check the config file for selection mode
        return config('guarantors.selection_mode', 'selectable') === 'all_members';
    }

    protected function getDefaultAllMemberGuarantors(Forms\Get $get): array
    {
        // Log::info('getDefaultAllMemberGuarantors');
        $groupId = $get('group_id');
        $currentMemberId = $get('member_id');
        $principalAmount = (float) str_replace(',', '', $get('principal_amount') ?? 0);

        if (!$groupId || !$currentMemberId || $principalAmount <= 0) {
            return [];
        }

        // dd($groupId, $currentMemberId, $principalAmount);
        // Log::info($groupId, $currentMemberId, $principalAmount);

        // Get all members in the group except the loan applicant
        $members = Member::whereHas('groups', function ($query) use ($groupId) {
            $query->where('groups.id', $groupId);
        })
            ->where('id', '!=', $currentMemberId)
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        // Share the principal amount equally among all group members
        $equalAmount = $principalAmount / $members->count();

        return $members->map(function ($member) use ($equalAmount) {
            return [
                'member_id' => $member->id,
                'name' => $member->name,
                'amount' => number_format($equalAmount, 2, '.', ''),
            ];
        })->toArray();
    }

    /**
     * Check if member has all required KYC documents and show notification immediately
     */
    protected function checkKycDocumentsAndNotify(Forms\Get $get, Forms\Set $set): void
    {
        $memberId = $get('member_id');
        $loanProductId = $get('loan_product_id');

        if (!$memberId || !$loanProductId) {
            return;
        }

        $member = Member::find($memberId);
        if (!$member) {
            return;
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return;
        }

        // Get attachments_required attribute
        $attachmentsAttr = LoanAttribute::where('slug', 'attachments_required')->first();
        if (!$attachmentsAttr) {
            return; // No attachments required attribute found
        }

        $loanProductAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $attachmentsAttr->id)
            ->first();

        if (!$loanProductAttr || !$loanProductAttr->value) {
            return; // No attachments required for this product
        }

        // Parse the attachments_required value
        $requiredDocTypes = is_string($loanProductAttr->value) && strpos($loanProductAttr->value, ',') !== false
            ? explode(',', $loanProductAttr->value)
            : json_decode($loanProductAttr->value, true);

        if (!is_array($requiredDocTypes) || empty($requiredDocTypes)) {
            return;
        }

        // Get member's uploaded KYC documents
        $memberDocTypes = $member->kycDocuments()->pluck('document_type')->toArray();

        // Check if all required documents are uploaded
        $missingDocs = array_diff($requiredDocTypes, $memberDocTypes);

        if (!empty($missingDocs)) {
            // Set flag to disable next button and form
            $set('kyc_incomplete', true);
            $set('missing_kyc_docs', $missingDocs);

            // Get document names for display
            $docNames = \App\Models\DocsMeta::whereIn('id', $missingDocs)->pluck('name')->toArray();

            // Set Livewire properties to show modal and disable form
            $this->missingKycDocs = $docNames;
            $this->kycMemberId = $memberId;
            $this->showKycModal = true;
        } else {
            $set('kyc_incomplete', false);
            $set('missing_kyc_docs', []);
            $this->showKycModal = false;
            $this->missingKycDocs = [];
            $this->kycMemberId = null;
        }
    }

    /**
     * Check if member has all required KYC documents
     */
    protected function checkKycDocuments(Forms\Get $get, Forms\Set $set): void
    {
        $memberId = $get('member_id');
        $loanProductId = $get('loan_product_id');

        if (!$memberId || !$loanProductId) {
            return;
        }

        $member = Member::find($memberId);
        if (!$member) {
            return;
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return;
        }

        // Get attachments_required attribute
        $attachmentsAttr = LoanAttribute::where('slug', 'attachments_required')->first();
        if (!$attachmentsAttr) {
            return; // No attachments required attribute found
        }

        $loanProductAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $attachmentsAttr->id)
            ->first();

        if (!$loanProductAttr || !$loanProductAttr->value) {
            return; // No attachments required for this product
        }

        // Parse the attachments_required value
        $requiredDocTypes = is_string($loanProductAttr->value) && strpos($loanProductAttr->value, ',') !== false
            ? explode(',', $loanProductAttr->value)
            : json_decode($loanProductAttr->value, true);

        if (!is_array($requiredDocTypes) || empty($requiredDocTypes)) {
            return;
        }

        // Get member's uploaded KYC documents
        $memberDocTypes = $member->kycDocuments()->pluck('document_type')->toArray();

        // Check if all required documents are uploaded
        $missingDocs = array_diff($requiredDocTypes, $memberDocTypes);

        if (!empty($missingDocs)) {
            // Set flag to disable next button
            $set('kyc_incomplete', true);
            $set('missing_kyc_docs', $missingDocs);
        } else {
            $set('kyc_incomplete', false);
            $set('missing_kyc_docs', []);
        }
    }

    public function submit(): void
    {
        // Prevent submission if KYC is incomplete
        if ($this->showKycModal) {
            Notification::make()
                ->title('Cannot Submit Application')
                ->body('Please complete all required KYC documents before submitting the loan application.')
                ->danger()
                ->send();
            return;
        }

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
            // dd($guarantorsRequired);
            if ($guarantorsRequired) {
                $isValid = $this->validateGuarantors($data);
                if (!$isValid) {
                    return;
                }
            }

            // Validate collaterals if required
            $collateralsRequired = $this->checkCollateralsRequiredForData($data);
            if ($collateralsRequired) {
                $isValid = $this->validateCollaterals($data);
                if (!$isValid) {
                    return;
                }
            }

            $existingLoan = Loan::where('member_id', $data['member_id'])
                ->where('loan_product_id', $data['loan_product_id'])
                ->where('status', 'Incomplete Application')
                ->first();

            $principalAmount = (float) str_replace(',', '', $data['principal_amount'] ?? 0);

            $loanData = [
                'loan_number' => $data['loan_number'] ?? $this->generateLoanNumber($data['member_id']),
                'principal_amount' => $principalAmount,
                'applied_amount' => $principalAmount, // Store applied amount (same as principal initially)
                'interest_rate' => (float) ($data['interest_rate'] ?? 0),
                'interest_amount' => 0,
                'repayment_amount' => (float) str_replace(',', '', $data['repayment_amount'] ?? 0),
                'release_date' => $data['release_date'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'loan_duration' => (int) ($data['loan_duration'] ?? 0),
                'loan_purpose' => $data['loan_purpose'] ?? '',
                'collateral_type' => $data['collateral_type'] ?? '',
                'collateral_value' => (float) str_replace(',', '', $data['collateral_value'] ?? 0),
                'collateral_description' => $data['collateral_description'] ?? '',
                'additional_notes' => $data['additional_notes'] ?? '',
                'status' => 'Pending Approval',
                'session_data' => $data, // Keep session data for reference
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

            // Save guarantors to loan_guarantors table
            $this->saveGuarantorsToDatabase($loan, $data);

            // Save collateral attachments to loan_collateral_attachments table
            $this->saveCollateralsToDatabase($loan, $data);

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

        // When guarantors are required, all group members are guarantors
        $guarantors = $data['all_member_guarantors'] ?? [];
        // dd($guarantors, $data);

        if (empty($guarantors)) {
            Notification::make()
                ->title('Validation Error')
                ->body('No guarantors found. Please ensure all group members are listed as guarantors.')
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

        return true;
    }

    protected function isGuarantorsRequired(array $data): bool
    {
        // dd($data);
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
            if ($loanAttr && $loanAttr->slug === 'is_guarantors_required') {
                return (bool) $attr->value;
            }
        }

        return false;
    }

    protected function checkCollateralsRequired(Forms\Get $get): bool
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
        $collateralsAttr = LoanAttribute::where('slug', 'is_collaterals_required')->first();
        if (!$collateralsAttr) {
            return false;
        }

        // dd($collateralsAttr);

        $collateralsAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $collateralsAttr->id)
            ->first()
            ?->value;

        // dd($collateralsAttr, (bool) $collateralsAttr,  $collateralsAttr, $loanProductId, $loanProduct->LoanProductAttributes);

        if ($collateralsAttr == "false" || $collateralsAttr == 0 || $collateralsAttr == "0") {
            return false;
        } elseif ($collateralsAttr == "true" || $collateralsAttr == 1 || $collateralsAttr == "1") {
            return true;
        }

        // Default return
        return false;
    }



    protected function isAllMembersGuarantee(array $data): bool
    {
        // Check the config file for selection mode
        return config('guarantors.selection_mode', 'selectable') === 'all_members';
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

    /**
     * Save guarantors to the loan_guarantors table
     */
    protected function saveGuarantorsToDatabase($loan, array $data): void
    {
        if (!$loan) {
            return;
        }

        // Check if guarantors are required
        if (!$this->isGuarantorsRequired($data)) {
            return;
        }

        // Delete existing guarantors for this loan
        \App\Models\LoanGuarantor::where('loan_id', $loan->id)->delete();

        // When guarantors are required, use all_member_guarantors (all group members)
        $guarantors = $data['all_member_guarantors'] ?? [];

        // Save each guarantor
        foreach ($guarantors as $guarantorData) {
            if (!isset($guarantorData['member_id']) || !isset($guarantorData['amount'])) {
                continue;
            }

            $guarantorMember = Member::find($guarantorData['member_id']);

            if ($guarantorMember) {
                $guaranteedAmount = (float) str_replace(',', '', $guarantorData['amount']);

                \App\Models\LoanGuarantor::create([
                    'loan_id' => $loan->id,
                    'guarantor_member_id' => $guarantorData['member_id'],
                    'guaranteed_amount' => $guaranteedAmount,
                    'guarantor_savings_at_guarantee' => $guarantorMember->total_savings ?? 0,
                    'status' => 'pending',
                ]);
            }
        }
    }

    /**
     * Save collateral attachments to the loan_collateral_attachments table
     */
    protected function saveCollateralsToDatabase($loan, array $data): void
    {
        if (!$loan) {
            return;
        }

        // Check if collaterals are required
        if (!$this->checkCollateralsRequiredForData($data)) {
            return;
        }

        // Delete existing collateral attachments for this loan
        \App\Models\LoanCollateralAttachment::where('loan_id', $loan->id)->delete();

        // Get loan product to find required document types
        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($data['loan_product_id'] ?? null);
        if (!$loanProduct) {
            return;
        }

        // Get collateral_attachments_required attribute
        $collateralAttr = LoanAttribute::where('slug', 'collateral_attachments_required')->first();
        if (!$collateralAttr) {
            return;
        }

        $loanProductAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $collateralAttr->id)
            ->first();

        if (!$loanProductAttr || !$loanProductAttr->value) {
            return;
        }

        $requiredDocTypes = explode(',', $loanProductAttr->value);
        if (!is_array($requiredDocTypes) || empty($requiredDocTypes)) {
            return;
        }

        // Save each collateral attachment
        foreach ($requiredDocTypes as $docTypeId) {
            $fieldName = 'collateral_attachment_' . $docTypeId;
            $filePath = $data[$fieldName] ?? null;

            if ($filePath) {
                \App\Models\LoanCollateralAttachment::create([
                    'loan_id' => $loan->id,
                    'document_type' => $docTypeId,
                    'file_path' => $filePath,
                ]);
            }
        }
    }

    /**
     * Validate that all required collateral documents are uploaded
     */
    protected function validateCollaterals(array $data): bool
    {
        $loanProductId = $data['loan_product_id'] ?? null;
        if (!$loanProductId) {
            return true; // No loan product selected, skip validation
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return true; // Loan product not found, skip validation
        }

        // Get collateral_attachments_required attribute
        $collateralAttr = LoanAttribute::where('slug', 'collateral_attachments_required')->first();
        if (!$collateralAttr) {
            return true; // No collateral attribute, skip validation
        }

        $loanProductAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $collateralAttr->id)
            ->first();

        if (!$loanProductAttr || !$loanProductAttr->value) {
            return true; // No collaterals required, validation passes
        }

        $requiredDocTypes = explode(',', $loanProductAttr->value);
        if (!is_array($requiredDocTypes) || empty($requiredDocTypes)) {
            return true; // No document types specified, validation passes
        }

        // Check if all required documents are uploaded
        $missingDocs = [];
        foreach ($requiredDocTypes as $docTypeId) {
            $fieldName = 'collateral_attachment_' . $docTypeId;
            $filePath = $data[$fieldName] ?? null;

            if (empty($filePath)) {
                $docType = \App\Models\DocsMeta::find($docTypeId);
                $missingDocs[] = $docType ? $docType->name : "Document Type ID: {$docTypeId}";
            }
        }

        if (!empty($missingDocs)) {
            Notification::make()
                ->title('Missing Collateral Documents')
                ->body('Please upload all required collateral documents: ' . implode(', ', $missingDocs))
                ->danger()
                ->send();
            return false;
        }

        return true;
    }

    /**
     * Check if collaterals are required for the given data
     */
    protected function checkCollateralsRequiredForData(array $data): bool
    {
        $loanProductId = $data['loan_product_id'] ?? null;
        if (!$loanProductId) {
            return false;
        }

        $loanProduct = LoanProduct::with('LoanProductAttributes')->find($loanProductId);
        if (!$loanProduct) {
            return false;
        }

        $collateralsAttr = LoanAttribute::where('slug', 'is_collaterals_required')->first();
        if (!$collateralsAttr) {
            return false;
        }

        $collateralsAttr = $loanProduct->LoanProductAttributes
            ->where('loan_attribute_id', $collateralsAttr->id)
            ->first();

        if (!$collateralsAttr || !$collateralsAttr->value) {
            return false;
        }

        if ($collateralsAttr->value == "false" || $collateralsAttr->value == 0 || $collateralsAttr->value == "0") {
            return false;
        } elseif ($collateralsAttr->value == "true" || $collateralsAttr->value == 1 || $collateralsAttr->value == "1") {
            return true;
        }

        return (bool) $collateralsAttr->value;
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

    public function setLoanAttributes(callable $set, $loanProduct, callable $get)
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

        // dd($arranged_attributes);

        $this->setLoanParticulars($set, $arranged_attributes, $get);
    }

    public function setLoanParticulars($set, $arranged_attributes, callable $get)
    {
        foreach ($arranged_attributes as $attribute) {
            // Special handling for max_loan_amount - check group first
            if ($attribute['slug'] === 'max_loan_amount') {
                // dd($arranged_attributes, "here");
                $maxLoanAmount = $this->getMaxLoanAmount($set, $get);
                // dd($maxLoanAmount);
                if ($maxLoanAmount !== null) {
                    // dd(number_format($maxLoanAmount, 2));
                    $set('max_loan_amount', $maxLoanAmount);
                    continue;
                }
            }

            // $maxLoanAmount = $get('max_loan_amount') ?? null;
            // dd($maxLoanAmount);

            try {
                $currentVal = (int)($attribute['value']);
                $set($attribute['slug'], $currentVal > 0 ? number_format($currentVal, 2) : $attribute['value']);
            } catch (\Exception $e) {
                Log::error('Error setting loan attribute: ' . $e->getMessage());
            }


        }
    }

    /**
     * Get max loan amount - check group first, then fall back to loan attributes
     */
    protected function getMaxLoanAmount($set, callable $get): ?float
    {
        $groupId = $get('group_id') ?? null;
        $memberId = $get('member_id') ?? null;

        // If we have member_id, get group from member
        if ($memberId && !$groupId) {
            $member = Member::find($memberId);
            if ($member) {
                // Get the first group from the many-to-many relationship
                $firstGroup = $member->groups()->first();
                $groupId = $firstGroup ? $firstGroup->id : null;
            }
        }

        // Check group's max_loan_amount first
        if ($groupId) {
            $group = Group::find($groupId);
            if ($group && $group->max_loan_amount !== null) {
                return (float) $group->max_loan_amount;
            }
        }

        // Fall back to loan attributes (will be set in the loop)
        return null;
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
