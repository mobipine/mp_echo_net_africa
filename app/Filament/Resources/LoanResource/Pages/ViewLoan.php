<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use App\Models\Loan;
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

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    public ?array $data = [];

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        
        // Populate the data array with loan record data
        $this->data = [
            'loan_number' => $this->record->loan_number,
            'status' => $this->record->status,
            'principal_amount' => $this->record->principal_amount,
            'interest_amount' => $this->record->interest_amount,
            'repayment_amount' => $this->record->repayment_amount,
            'release_date' => $this->record->release_date,
            'due_at' => $this->record->due_at,
            'loan_duration' => $this->record->loan_duration,
            'loan_purpose' => $this->record->loan_purpose,
            'guarantor_name' => $this->record->guarantor_name,
            'guarantor_phone' => $this->record->guarantor_phone,
            'guarantor_national_id' => $this->record->guarantor_national_id,
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
        ];

        // $this->data['member_id'] = $this->record->member->id;
        //loop through the member and add to the data
        $memberDetails = $this->record->member->toArray();
        foreach ($memberDetails as $key => $attribute) {
            $this->data[$key] = $attribute;
        }
        
        
        //foreach the all_attributes and add to the data
        $allAttributes = $this->record->all_attributes;
        foreach ($allAttributes as $key => $attribute) {
            $this->data[$key] = $attribute['value'];
        }
        
        // dd($allAttributes->toArray());
    
        // dd($this->data);
        $this->form->fill($this->data);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Actions\EditAction::make(),
            // Actions\DeleteAction::make(),

            //add an approve action if the status is pending approval
            // Actions\Action::make('approve')
            //     ->label('Approve Loan')
            //     ->icon('heroicon-o-check-circle')
            //     ->color('success')
            //     ->visible(fn(Loan $record): bool => $record->status === 'Pending Approval')
            //     ->action(function (Loan $record) {
            //         $record->update([
            //             'status' => 'Approved',
            //         ]);
            //     }),
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
                        ->schema($this->getApprovalInformationSchema()),
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
                Textarea::make('loan_purpose')
                    ->label('Loan Purpose')
                    ->rows(3)
                    ->disabled(),
                    
                Textarea::make('guarantor_name')
                    ->label('Guarantor Name')
                    ->disabled(),
                    
                Textarea::make('guarantor_phone')
                    ->label('Guarantor Phone')
                    ->disabled(),
                    
                Textarea::make('guarantor_id')
                    ->label('Guarantor ID')
                    ->disabled(),
                    
                Textarea::make('guarantor_address')
                    ->label('Guarantor Address')
                    ->rows(3)
                    ->disabled(),
                    
                Textarea::make('collateral_type')
                    ->label('Collateral Type')
                    ->disabled(),
                    
                Textarea::make('collateral_value')
                    ->label('Collateral Value')
                    ->disabled(),
                    
                Textarea::make('collateral_description')
                    ->label('Collateral Description')
                    ->rows(3)
                    ->disabled(),
                    
                Textarea::make('additional_notes')
                    ->label('Additional Notes')
                    ->rows(3)
                    ->disabled(),
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
                    
                DatePicker::make('approved_at')
                    ->label('Approved At')
                    ->disabled(),
                    
                TextInput::make('remaining_balance')
                    ->label('Remaining Balance')
                    ->prefix('KES')
                    ->disabled(),
                    
                TextInput::make('total_repaid')
                    ->label('Total Repaid')
                    ->prefix('KES')
                    ->disabled(),
            ]),
        ];
    }
}
