<?php

namespace App\Filament\Clusters\Settings\Resources\NotificationTemplateResource\Pages;

use App\Filament\Clusters\Settings\Resources\NotificationTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
