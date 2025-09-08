<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use App\Models\Member;
use Filament\Pages\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Livewire\WithFileUploads;

class EditMemberTabbed extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static string $resource = MemberResource::class;
    
    protected static string $view = 'filament.resources.member-resource.pages.edit-member-tabbed';

    public string $activeTab = 'Edit Details';

    // public ?Model $record = null;
    public ?Member $record = null; // 👈 FIXED: use concrete model


    public function mount($record): void
    {
        $this->record = MemberResource::getModel()::findOrFail($record);
        $this->form->fill($this->record->toArray());
    }

    protected function getFormSchema(): array
    {
        return MemberResource::form([])->getSchema();
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $this->record->update($data);

        $this->notify('success', 'Member updated successfully!');
    }
}
