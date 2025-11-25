<?php

namespace App\Filament\Resources\SmsReportResource\Pages;

use App\Filament\Resources\SmsReportResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSmsReport extends EditRecord
{
    protected static string $resource = SmsReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
