<?php

namespace App\Enums;

enum QuestionPurpose: string
{
    case REGULAR = 'regular';
    case EDIT_NAME = 'edit_name';
    case EDIT_ID = 'edit_id';
    case EDIT_GENDER = 'edit_gender';
    case EDIT_GROUP = 'edit_group';
    case EDIT_YEAR_OF_BIRTH = 'edit_year_of_birth';
    case CONFIRM = 'confirm';
    case INFO = 'info';
    case LOAN_DATE = 'loan_received_date';
    case LOAN_RECEIVED = 'loan_amount_received';
    case REDO_REASON='redo_reason';
    case REDO_REQUEST ='redo_request';

    public function label(): string
    {
        return match($this) {
            self::REGULAR => 'Regular Question',
            self::EDIT_NAME => 'Edit Name',
            self::EDIT_ID => 'Edit ID',
            self::EDIT_GENDER => 'Edit Gender',
            self::EDIT_GROUP => 'Edit to which Group a member belongs',
            self::EDIT_YEAR_OF_BIRTH => 'Edit Year of Birth',
            self::CONFIRM => 'Confirm Details',
            self::INFO => 'Informational',
            self::LOAN_DATE => 'Gets when the loan was received',
            self::LOAN_RECEIVED => 'Gets what amount was received',
            self::REDO_REASON=>'This will get the reason to redo a survey',
            self::REDO_REQUEST=>'To know if a member wihses to redo a survey'
        };
    }

    /** 
     * Return all enum cases as array usable in Filament select options
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }

    public function slug(): string
    {
        return match($this) {
            // Default to the enum value for other cases
            default => $this->value, 
        };
    }
}
