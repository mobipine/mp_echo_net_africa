<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class UssdFlowBuilder extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static string $view = 'filament.pages.ussd-flow-builder';

    public $flowId;

    protected static ?string $slug = 'ussd-flow-builder/{flow}';

    public function mount($flow): void
    {
        $this->flowId = $flow;
    }

    public function getTitle(): string
    {
        return 'USSD Flow Builder';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false; // This page won't appear in navigation
    }
}

