<?php

namespace App\Filament\Resources\LoanAmortizationScheduleResource\Pages;

use App\Filament\Resources\LoanAmortizationScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLoanAmortizationSchedules extends ListRecords
{
    protected static string $resource = LoanAmortizationScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
