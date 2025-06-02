<x-filament::page>
    <x-filament::tabs :tabs="['Edit Details', 'Dependants', 'KYC Documents']" wire:model="activeTab" />

    <div x-data="{ tab: @entangle('activeTab') }" class="mt-6">
        <div x-show="tab === 'Edit Details'">
            {{ $this->form }}
            <x-filament::button wire:click="save" class="mt-4">
                Save Changes
            </x-filament::button>
        </div>

        <div x-show="tab === 'Dependants'">
            @livewire(\App\Filament\Resources\MemberResource\RelationManagers\DependantsRelationManager::class, ['ownerRecord' => $record], key('dependants-'.$record->id))
        </div>

        <div x-show="tab === 'KYC Documents'">
            @livewire(\App\Filament\Resources\MemberResource\RelationManagers\KycDocumentsRelationManager::class, ['ownerRecord' => $record], key('kyc-'.$record->id))
        </div>
    </div>
</x-filament::page>
