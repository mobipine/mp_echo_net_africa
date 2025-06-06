<?php

namespace App\Filament\Pages;

use App\Models\Member;
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
use Illuminate\Support\Facades\Blade;
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
    public $email;
    public $phone;
    public $national_id;
    public $gender;
    public $marital_status;

    public function mount(): void
    {
        $this->form->fill();
    }

    //do a function to return a form wizard with 4 steps
    protected function getFormSchema(): array
    {
        return [
            Wizard::make([
                Step::make('Member Details')
                ->icon('heroicon-o-user')
                ->beforeValidation(function () {
                   
                })
                ->afterValidation(function () {
                    //use this to handle any logic after the validation e.g save the data to the database
                    // dd($this->form->getState());
                })
                ->schema([
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
                        TextInput::make('name')->disabled()->dehydrated(),
                        TextInput::make('email')->email()->disabled()->dehydrated(),
                        TextInput::make('phone')->required()->disabled()->dehydrated(),
                        TextInput::make('national_id')->required()->disabled()->dehydrated(),
                        Select::make('gender')->options([
                            'male' => 'Male',
                            'female' => 'Female',
                        ])->disabled()->dehydrated(),
                        Select::make('marital_status')->options([
                            'single' => 'Single',
                            'married' => 'Married',
                        ])->disabled()->dehydrated(),
                        
                    ]),
                ]),
                    



                Step::make('Loan Particulars')->schema([
                    // ...
                ]),
                Step::make('Loan Guarantors')->schema([
                    // ...
                ]),
                Step::make('Loan Collaterals')->schema([
                    // ...
                ]),
            ])
                ->persistStepInQueryString()
                ->nextAction(
                    fn(Action $action) => $action->label('Next step'),
                )
                ->previousAction(
                    fn(Action $action) => $action->label('Previous step'),
                )
                ->submitAction($this->renderBtn())
                //add a function to handle the submit action
                
        ];
    }

    public function fillMemberDetails(callable $set, $memberId)
    {
        if ($memberId) {
            $member = Member::find($memberId);
            // dd($member);
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

    protected function getFormModel(): string
    {
        return self::class;
    }

    public function submit()
    {

        dd([
            'name' => $this->name,
            'email' => $this->email,
        ]);
    }

    public function renderBtn()
    {
        return new HtmlString(Blade::render(<<<BLADE
            <x-filament::button type="submit" size="sm">Submit</x-filament::button>
        BLADE));
    }
}
