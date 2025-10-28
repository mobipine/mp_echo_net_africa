<?php

namespace App\Filament\Resources\CapitalTransferResource\Pages;

use App\Filament\Resources\CapitalTransferResource;
use App\Services\CapitalTransferService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateCapitalTransfer extends CreateRecord
{
    protected static string $resource = CapitalTransferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['approved_by'] = auth()->id();
        $data['status'] = 'completed';
        
        return $data;
    }

    protected function afterCreate(): void
    {
        $transfer = $this->record;
        $capitalTransferService = app(CapitalTransferService::class);
        
        try {
            if ($transfer->transfer_type === 'advance') {
                // Capital advance was created - process it
                $capitalTransferService->advanceCapitalToGroup(
                    $transfer->group,
                    $transfer->amount,
                    $transfer->purpose ?? 'Capital advance',
                    $transfer->approved_by,
                    $transfer->reference_number
                );
                
                // Delete the initially created record as the service creates a new one with transactions
                $transfer->delete();
                
                Notification::make()
                    ->success()
                    ->title('Capital Advanced Successfully')
                    ->body("KES {$transfer->amount} has been advanced to {$transfer->group->name}")
                    ->send();
                    
            } else {
                // Capital return
                $capitalTransferService->returnCapitalToOrganization(
                    $transfer->group,
                    $transfer->amount,
                    $transfer->created_by,
                    $transfer->notes
                );
                
                // Delete the initially created record as the service creates a new one with transactions
                $transfer->delete();
                
                Notification::make()
                    ->success()
                    ->title('Capital Returned Successfully')
                    ->body("KES {$transfer->amount} has been returned from {$transfer->group->name}")
                    ->send();
            }
        } catch (\Exception $e) {
            // Delete the failed transfer
            $transfer->delete();
            
            Notification::make()
                ->danger()
                ->title('Transfer Failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

