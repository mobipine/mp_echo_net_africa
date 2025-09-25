<?php

namespace App\Filament\Resources\MemberEditRequestResource\Pages;

use App\Filament\Resources\MemberEditRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMemberEditRequest extends EditRecord
{
    protected static string $resource = MemberEditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
