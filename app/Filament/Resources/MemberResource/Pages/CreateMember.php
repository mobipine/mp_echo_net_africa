<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
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
