<?php

namespace App\Filament\Resources\UssdFlowResource\Pages;

use App\Filament\Pages\UssdFlowBuilder;
use App\Filament\Resources\UssdFlowResource;
use App\Models\UssdFlow;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUssdFlow extends EditRecord
{
    protected static string $resource = UssdFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('build_flow')
                ->label('Build Flow')
                ->icon('heroicon-o-cursor-arrow-rays')
                ->color('primary')
                ->url(fn () => UssdFlowBuilder::getUrl(['flow' => $this->record->id])),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // If setting as active, deactivate other flows of the same type
        if ($data['is_active'] ?? false) {
            UssdFlow::where('flow_type', $data['flow_type'])
                ->where('id', '!=', $this->record->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        return $data;
    }
}

