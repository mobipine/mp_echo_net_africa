<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('export_members')
                ->label('Export to Excel')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): void {
                    MemberResource::queueMembersExport(userId: auth()->id());

                    Notification::make()
                        ->title('Member export queued')
                        ->body('The export is running in the background. You will get a notification when it is ready.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
