<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use App\Models\Loan;
use App\Models\Member;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    public ?array $data = [];

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Eager load guarantors with their member details
        $this->record->load([
            // 'guarantors.guarantorMember',
            'member',
            // 'loanProduct.LoanProductAttributes'
            'guarantors',
            'rejectedBy',
            'collateralAttachments.documentType'
        ]);

        // Populate the data array with loan record data (handle loans with no linked member)
        $this->data = [
            'group_name' => $this->record->member?->groups()->first()?->name ?? $this->record->member?->group?->name ?? 'N/A',
            'loan_number' => $this->record->loan_number,
            'status' => $this->record->status,
            'principal_amount' => $this->record->principal_amount,
            'applied_amount' => $this->record->applied_amount,
            'interest_amount' => $this->record->interest_amount,
            'repayment_amount' => $this->record->repayment_amount,
            'release_date' => $this->record->release_date,
            'due_at' => $this->record->due_at,
            'loan_duration' => $this->record->loan_duration,
            'loan_purpose' => $this->record->loan_purpose,
            'collateral_description' => $this->record->collateral_description,
            'collateral_value' => $this->record->collateral_value,
            'additional_notes' => $this->record->additional_notes,
            'approved_at' => $this->record->approved_at,
            'remaining_balance' => $this->record->remaining_balance,
            'total_repaid' => $this->record->total_repaid,
            'interest_rate' => $this->record->interest_rate,
            'interest_type' => $this->record->interest_type,
            'interest_accrual_moment' => $this->record->interest_accrual_moment,
            'interest_cycle' => $this->record->interest_cycle,
            'max_loan_amount' => $this->record->max_loan_amount,
            'loan_charges' => $this->record->loan_charges,
            'loan_product_name' => $this->record->loan_product_name,
            'approved_by_name' => $this->record->approved_by_name,
            'rejected_by_name' => $this->record->rejectedBy?->name,
            'rejected_at' => $this->record->rejected_at,
            'rejection_reason' => $this->record->rejection_reason,
        ];

        // $this->data['member_id'] = $this->record->member->id;
        // Loop through the member and add to the data (only when member exists)
        if ($this->record->member) {
            $memberDetails = $this->record->member->toArray();
            foreach ($memberDetails as $key => $attribute) {
                $this->data[$key] = $attribute;
            }
        }


        //foreach the all_attributes and add to the data
        $allAttributes = $this->record->all_attributes;
        foreach ($allAttributes as $key => $attribute) {
            $this->data[$key] = $attribute['value'];
        }

        // dd($allAttributes->toArray());

        //add all guarantors
        $allGuarantors = $this->record->guarantors->toArray();
        // dd($allGuarantors);
        $this->data['guarantors'] = $allGuarantors;

        //add all collateral attachments
        $allCollaterals = $this->record->collateralAttachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'document_type' => $attachment->document_type,
                'document_type_name' => $attachment->documentType->name ?? 'Unknown',
                'file_path' => $attachment->file_path,
            ];
        })->toArray();
        $this->data['collateral_attachments'] = $allCollaterals;

        // dd($this->data);
        $this->form->fill($this->data);
    }

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Resources\LoanResource\Actions\ApproveLoanAction::makeForViewRecord($this->record)
                ->after(function () {
                    $this->redirect(\App\Filament\Resources\LoanResource::getUrl('view', ['record' => $this->record]));
                }),
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
            Tabs::make('Loan Details')
                ->tabs([
                    Tab::make('Basic Information')
                        ->icon('heroicon-o-document-text')
                        ->schema($this->getBasicInformationSchema()),

                    Tab::make('Member Information')
                        ->icon('heroicon-o-user')
                        ->schema($this->getMemberInformationSchema()),

                    Tab::make('Loan Product Details')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema($this->getLoanProductDetailsSchema()),

                    Tab::make('Application Details')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->schema($this->getApplicationDetailsSchema()),

                    Tab::make('Approval Information')
                        ->icon('heroicon-o-check-circle')
                        ->schema($this->getApprovalInformationSchema())
                        ->visible(fn() => $this->record->status === 'Approved'),

                    Tab::make('Rejection Information')
                        ->icon('heroicon-o-x-circle')
                        ->schema($this->getRejectionInformationSchema())
                        ->visible(fn() => $this->record->status === 'Rejected'),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getBasicInformationSchema(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('loan_number')
                    ->label('Loan Number')
                    ->disabled(),

                Select::make('status')
                    ->label('Status')
                    ->options([
                        'Incomplete Application' => 'Incomplete Application',
                        'Pending Approval' => 'Pending Approval',
                        'Approved' => 'Approved',
                        'Rejected' => 'Rejected',
                        'Active' => 'Active',
                        'Completed' => 'Completed',
                        'Defaulted' => 'Defaulted',
                    ])
                    ->disabled(),

                TextInput::make('principal_amount')
                    ->label('Principal Amount')
                    ->prefix('KES')
                    ->disabled(),
                TextInput::make('applied_amount')
                    ->label('Applied Amount')
                    ->prefix('KES')
                    ->disabled(),

                TextInput::make('interest_rate')
                    ->label('Interest Rate')
                    ->suffix('%')
                    ->disabled(),

                TextInput::make('interest_amount')
                    ->label('Interest Amount')
                    ->prefix('KES')
                    ->disabled(),

                TextInput::make('repayment_amount')
                    ->label('Total Repayment Amount')
                    ->prefix('KES')
                    ->disabled(),

                DatePicker::make('release_date')
                    ->label('Release Date')
                    ->disabled(),

                DatePicker::make('due_at')
                    ->label('Due Date')
                    ->disabled(),

                TextInput::make('loan_duration')
                    ->label('Loan Duration')
                    ->disabled(),
            ]),
        ];
    }

    protected function getMemberInformationSchema(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('name')
                    ->label('Member Name')
                    ->disabled(),
                //group name
                TextInput::make('group_name')
                    ->label('Group Name')
                    ->disabled(),


                TextInput::make('email')
                    ->label('Email')
                    ->disabled(),

                TextInput::make('phone')
                    ->label('Phone')
                    ->disabled(),

                TextInput::make('national_id')
                    ->label('National ID')
                    ->disabled(),

                TextInput::make('gender')
                    ->label('Gender')
                    ->disabled(),

                TextInput::make('marital_status')
                    ->label('Marital Status')
                    ->disabled(),
            ]),
        ];
    }

    protected function getLoanProductDetailsSchema(): array
    {

        //do a foreach loop to display the all_attributes
        $allAttributes = $this->record->all_attributes;
        $fields = [];
        foreach ($allAttributes as $key => $attribute) {
            $fields[] = TextInput::make($key)
                ->label($attribute['name'])
                //autofill the value from the all_attributes
                ->default($attribute['value'])
                ->disabled();
        }

        return [
            Grid::make(2)->schema($fields),
        ];
    }

    protected function getApplicationDetailsSchema(): array
    {
        return [
            Grid::make(1)->schema([


                Section::make('Guarantors')
                    ->description('Members guaranteeing this loan')
                    ->schema([
                        Placeholder::make('guarantors_summary')
                            ->label('')
                            ->content(fn() => $this->getGuarantorsSummaryContent()),

                        Repeater::make('guarantors')
                            // ->relationship('guarantors')
                            ->schema([
                                Grid::make(4)->schema([
                                    TextInput::make('guarantor_member_id')
                                        ->label('Guarantor Name')
                                        ->formatStateUsing(function ($state) {
                                            $member = Member::find($state);
                                            return $member?->name ?? 'N/A';
                                        })
                                        ->disabled(),


                                    TextInput::make('guaranteed_amount')
                                        ->label('Guaranteed Amount')
                                        ->prefix('KES')
                                        ->mask(\Filament\Support\RawJs::make('$money($input)'))
                                        ->stripCharacters(',')
                                        ->disabled(),

                                ]),
                            ])
                            ->disabled(),
                    ])
                    ->visible(function () {
                        return ($this->record->guarantors ?? collect())->count() > 0;
                    }),

                Section::make('Collateral Attachments')
                    ->description('Documents uploaded as collateral for this loan')
                    ->schema([
                        Placeholder::make('collaterals_summary')
                            ->label('')
                            ->content(fn() => $this->getCollateralsSummaryContent()),

                        Repeater::make('collateral_attachments')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('document_type_name')
                                        ->label('Document Type')
                                        ->disabled(),

                                    Forms\Components\FileUpload::make('file_path')
                                        ->label('File')
                                        ->directory('loan-collaterals')
                                        ->visibility('public')
                                        ->disabled()
                                        ->downloadable()
                                        ->openable()
                                        ->dehydrated(false),
                                ]),
                            ])
                            ->disabled(),
                    ])
                    ->visible(function () {
                        return ($this->record->collateralAttachments ?? collect())->count() > 0;
                    }),


                //     Textarea::make('loan_purpose')
                //     ->label('Loan Purpose')
                //     ->rows(3)
                //     ->disabled(),

                // Textarea::make('collateral_type')
                //     ->label('Collateral Type')
                //     ->disabled(),

                // Textarea::make('collateral_value')
                //     ->label('Collateral Value')
                //     ->disabled(),

                // Textarea::make('collateral_description')
                //     ->label('Collateral Description')
                //     ->rows(3)
                //     ->disabled(),

                // Textarea::make('additional_notes')
                //     ->label('Additional Notes')
                //     ->rows(3)
                //     ->disabled(),
            ]),
        ];
    }

    protected function getApprovalInformationSchema(): array
    {
        return [
            Grid::make(2)->schema([

                TextInput::make('approved_by_name')
                    ->label('Approved By')
                    ->disabled(),

                TextInput::make('principal_amount')
                    ->label('Approved Amount')
                    ->prefix('KES')
                    ->mask(\Filament\Support\RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->disabled(),
                DatePicker::make('approved_at')
                    ->label('Approved At')
                    ->disabled(),

                TextInput::make('remaining_balance')
                    ->label('Remaining Balance')
                    ->prefix('KES')
                    ->mask(\Filament\Support\RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->disabled(),

                TextInput::make('total_repaid')
                    ->label('Total Repaid')
                    ->prefix('KES')
                    ->mask(\Filament\Support\RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->disabled(),
            ]),
        ];
    }

    protected function getRejectionInformationSchema(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('rejected_by_name')
                    ->label('Rejected By')
                    ->disabled(),

                DatePicker::make('rejected_at')
                    ->label('Rejected At')
                    ->disabled(),
            ]),

            Grid::make(1)->schema([
                Textarea::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->rows(4)
                    ->disabled()
                    ->columnSpanFull(),
            ]),
        ];
    }

    protected function getGuarantorsSummaryContent(): \Illuminate\Support\HtmlString
    {
        $loan = $this->record;
        $guarantors = $loan->guarantors ?? collect();
        $totalGuaranteed = $guarantors->sum('guaranteed_amount');
        $count = $guarantors->count();

        if ($count === 0) {
            return new \Illuminate\Support\HtmlString(
                '<div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No guarantors assigned to this loan.
                    </p>
                </div>'
            );
        }

        return new \Illuminate\Support\HtmlString(
            '<div class="p-4 rounded-lg border border-gray-700">
                <div class="flex justify-between items-center mb-2">
                    <span class="font-semibold text-sm text-gray-700 dark:text-white">Number of Guarantors:</span>
                    <span class="text-lg font-bold text-gray-900 dark:text-white">' . $count . '</span>
                </div>
                <div class="flex justify-between items-center pt-2 border-t border-green-300 dark:border-green-600">
                    <span class="font-semibold text-sm text-gray-700 dark:text-white">Total Guaranteed:</span>
                    <span class="text-lg font-bold text-gray-900 dark:text-white">KSh ' . number_format($totalGuaranteed, 2) . '</span>
                </div>
            </div>'
        );
    }

    protected function getCollateralsSummaryContent(): \Illuminate\Support\HtmlString
    {
        $loan = $this->record;
        $collaterals = $loan->collateralAttachments ?? collect();
        $count = $collaterals->count();

        if ($count === 0) {
            return new \Illuminate\Support\HtmlString(
                '<div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No collateral documents uploaded for this loan.
                    </p>
                </div>'
            );
        }

        $docTypes = $collaterals->map(function ($attachment) {
            return $attachment->documentType->name ?? 'Unknown';
        })->unique()->implode(', ');

        return new \Illuminate\Support\HtmlString(
            '<div class="p-4 rounded-lg border border-gray-700">
                <div class="flex justify-between items-center mb-2">
                    <span class="font-semibold text-sm text-gray-700 dark:text-white">Number of Documents:</span>
                    <span class="text-lg font-bold text-gray-900 dark:text-white">' . $count . '</span>
                </div>
                <div class="flex justify-between items-center pt-2 border-t border-gray-300 dark:border-gray-600">
                    <span class="font-semibold text-sm text-gray-700 dark:text-white">Document Types:</span>
                    <span class="text-sm text-gray-700 dark:text-white">' . $docTypes . '</span>
                </div>
            </div>'
        );
    }
}
