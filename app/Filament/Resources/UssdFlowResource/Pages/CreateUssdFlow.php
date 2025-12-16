<?php

namespace App\Filament\Resources\UssdFlowResource\Pages;

use App\Filament\Resources\UssdFlowResource;
use App\Models\UssdFlow;
use Filament\Resources\Pages\CreateRecord;

class CreateUssdFlow extends CreateRecord
{
    protected static string $resource = UssdFlowResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Initialize empty flow definition
        $data['flow_definition'] = [
            'nodes' => [],
            'edges' => []
        ];

        // If setting as active, deactivate other flows of the same type
        if ($data['is_active'] ?? false) {
            UssdFlow::where('flow_type', $data['flow_type'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        return $data;
    }
}

