<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    //change the title of the page
    public function getTitle(): string
    {
        return 'View Member';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function mutateFormDataBeforeSave(array $data): array
    {
        try{
            MemberResource::validateDob($data);
        } catch (ValidationException $e) {
        // Show a notification in the UI
        Notification::make()
            ->title('Member not saved')
            ->danger()
            ->body(implode(' ', $e->errors()['dob'])) // Show the dob validation message
            ->send();

        // Re-throw to prevent save
        throw $e;
        }
        //  // <-- validate here
         return $data;
    }
}
