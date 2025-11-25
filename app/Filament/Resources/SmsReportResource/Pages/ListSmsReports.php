<?php
namespace App\Filament\Resources\SmsReportResource\Pages;

use App\Filament\Resources\SmsReportResource;
use App\Filament\Resources\SmsReportResource\Widgets\SentSmsStatsOverview;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url; // Use Livewire v3 attribute for URL state

class ListSmsReports extends ListRecords
{
    use InteractsWithForms;

    protected static string $resource = SmsReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Assuming you don't need the Create button for a report page, 
            // you might remove this or change it.
            // Actions\CreateAction::make(),
        ];
    }
    
    // 💡 IMPORTANT: Use Livewire URL state to keep filters in the browser URL
    #[Url]
    public $start_date = null;

    #[Url]
    public $end_date = null;

    // Define the form schema (the date pickers)
    protected function getFormSchema(): array
    {
        return [
            DatePicker::make('start_date')
                ->label('Start Date')
                ->default(Carbon::today()->toDateString()) // Set default as string for form
                ->live() // Update component state on change
                ->afterStateUpdated(fn () => $this->dispatch('widget-updated')), // Dispatch event to refresh widgets

            DatePicker::make('end_date')
                ->label('End Date')
                ->afterOrEqual('start_date')
                ->live() // Update component state on change
                ->afterStateUpdated(fn () => $this->dispatch('widget-updated')), // Dispatch event to refresh widgets
        ];
    }

    // 💡 Render the custom header with the filter form
    public function getHeader(): ?\Illuminate\Contracts\View\View
    {
        return view('filament.sms-report.header', ['form' => $this->form]);
    }

    // 💡 Pass the form data (filters) to the widget instance
    protected function getStatsOverview(): array
    {
        return [
            SentSmsStatsOverview::make([
                'filters' => [
                    'start_date' => $this->start_date,
                    'end_date' => $this->end_date,
                ]
            ]),
        ];
    }
    
    // 💡 Ensure the widget is listed in this page
    protected function getHeaderWidgets(): array
    {
        return $this->getStatsOverview();
    }


    // Hide the table by returning a query that results in no records
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->whereRaw('1 = 0');
    }

    protected function getTableActions(): array { return []; }
    protected function getTableBulkActions(): array { return []; }
}