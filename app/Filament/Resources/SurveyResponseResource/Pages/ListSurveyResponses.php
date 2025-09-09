<?php

namespace App\Filament\Resources\SurveyResponseResource\Pages;

use App\Filament\Resources\SurveyResponseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
class ListSurveyResponses extends ListRecords
{
    protected static string $resource = SurveyResponseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            ExportAction::make()
                ->exports([
                    ExcelExport::make()->fromTable()
                ])
                ->label('Export to Excel')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray'),
        ];
    }
}
