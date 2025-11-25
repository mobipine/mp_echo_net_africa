<?php

namespace App\Filament\Resources\SmsReportResource\Pages;

use App\Filament\Resources\SmsReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSmsReport extends ViewRecord
{
    protected static string $resource = SmsReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
