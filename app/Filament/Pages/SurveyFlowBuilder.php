<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Str;

class SurveyFlowBuilder extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static string $view = 'filament.pages.survey-flow-builder';

    public $surveyId;

    protected static ?string $slug = 'survey-flow-builder/{survey}';

    public function mount($survey): void
    {
        $this->surveyId = $survey;
    }

    public static function getNavigationLabel(): string
    {
        return 'Survey Flow Builder';
    }

    public function getTitle(): string
    {
        return 'Survey Flow Builder';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false; // This page won't appear in navigation
    }
}