<?php

namespace App\Filament\Clusters\Settings\Resources\NotificationTemplateResource\Pages;

use App\Filament\Clusters\Settings\Resources\NotificationTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
