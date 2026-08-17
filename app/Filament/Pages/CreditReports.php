<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CreditStatsWidget;
use App\Filament\Widgets\CreditUtilizationTrendWidget;
use App\Jobs\GenerateCreditUtilizationReportJob;
use App\Models\County;
use App\Models\CreditReportExport;
use App\Models\CreditTransaction;
use App\Models\Group;
use App\Models\SMSInbox;
use App\Models\Survey;
use App\Services\CreditUtilizationReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CreditReports extends Page implements HasTable
{
    use ExposesTableToWidgets;
    use InteractsWithTable;

    public ?string $activeTab = null;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.credit-reports';

    protected static ?string $navigationGroup = 'SMS & Credits';

    protected static ?string $title = 'Credit Reports & Transactions';

    protected static ?int $navigationSort = 2;

    public function getSubheading(): ?string
    {
        return 'Analyze utilization, trace every credit movement, and generate audit-ready Excel workbooks.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(app(CreditUtilizationReportService::class)->query())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & time')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Direction')
                    ->formatStateUsing(fn (string $state): string => $state === 'add' ? 'Added' : 'Utilized')
                    ->colors([
                        'success' => 'add',
                        'danger' => 'subtract',
                    ])
                    ->icons([
                        'heroicon-o-plus-circle' => 'add',
                        'heroicon-o-minus-circle' => 'subtract',
                    ]),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Activity')
                    ->badge()
                    ->colors([
                        'success' => 'load',
                        'warning' => 'sms_sent',
                        'info' => 'sms_received',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'load' => 'Credit load',
                        'sms_sent' => 'Outbound SMS',
                        'sms_received' => 'Inbound SMS',
                        default => Str::headline($state),
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Credits')
                    ->formatStateUsing(fn ($state, CreditTransaction $record): string => ($record->type === 'add' ? '+' : '-').number_format($state)
                    )
                    ->color(fn (CreditTransaction $record): string => $record->type === 'add' ? 'success' : 'danger')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Balance')
                    ->formatStateUsing(fn ($state): string => number_format($state))
                    ->description(fn (CreditTransaction $record): string => 'Before: '.number_format($record->balance_before))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('report_survey')
                    ->label('Survey')
                    ->getStateUsing(fn (CreditTransaction $record): ?string => $record->attributedSurvey()?->title)
                    ->placeholder('Non-survey')
                    ->limit(34)
                    ->tooltip(fn (CreditTransaction $record): ?string => $record->attributedSurvey()?->title)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('report_member')
                    ->label('Member')
                    ->getStateUsing(fn (CreditTransaction $record): ?string => $record->attributedMember()?->name)
                    ->description(fn (CreditTransaction $record): ?string => $record->attributedMember()?->phone)
                    ->placeholder('System / unknown')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('report_channel')
                    ->label('Channel')
                    ->getStateUsing(fn (CreditTransaction $record): ?string => $record->attributedInbox()?->channel)
                    ->formatStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null)
                    ->badge()
                    ->placeholder('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->tooltip(fn (CreditTransaction $record): ?string => $record->description)
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Recorded by')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('System'),
            ])
            ->filters($this->transactionFilters())
            ->filtersFormColumns(2)
            ->filtersFormWidth(MaxWidth::FourExtraLarge)
            ->persistFiltersInSession()
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Search transaction descriptions')
            ->emptyStateHeading('No credit activity matches these filters')
            ->emptyStateDescription('Adjust or clear the report filters to broaden the result set.')
            ->emptyStateIcon('heroicon-o-funnel')
            ->striped()
            ->poll('30s');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('queue_excel_report')
                ->label('Generate Excel report')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->modalHeading('Generate credit utilization workbook')
                ->modalDescription('The active transaction filters are pre-filled below. The workbook is generated by the queue, and you can return to this page while it runs.')
                ->modalSubmitActionLabel('Queue workbook')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->fillForm(fn (): array => $this->currentReportFilters())
                ->form($this->exportFilterForm())
                ->action(fn (array $data) => $this->queueReport($data)),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CreditStatsWidget::class,
            CreditUtilizationTrendWidget::class,
        ];
    }

    public function getRecentExports()
    {
        return CreditReportExport::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->limit(8)
            ->get();
    }

    public function exportStatusColor(string $status): string
    {
        return match ($status) {
            CreditReportExport::STATUS_COMPLETED => 'success',
            CreditReportExport::STATUS_FAILED => 'danger',
            CreditReportExport::STATUS_PROCESSING => 'warning',
            default => 'gray',
        };
    }

    public function exportStatusLabel(string $status): string
    {
        return match ($status) {
            CreditReportExport::STATUS_PROCESSING => 'Generating',
            default => Str::headline($status),
        };
    }

    public function formatFileSize(?int $bytes): string
    {
        if (! $bytes) {
            return 'Size pending';
        }

        return match (true) {
            $bytes >= 1_073_741_824 => number_format($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 2).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1).' KB',
            default => number_format($bytes).' bytes',
        };
    }

    private function transactionFilters(): array
    {
        return [
            Filter::make('date_range')
                ->label('Date range')
                ->form([
                    DatePicker::make('from')->label('From')->native(false),
                    DatePicker::make('until')->label('Through')->native(false),
                ])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['date_from' => $data['from'] ?? null, 'date_to' => $data['until'] ?? null])),

            Filter::make('surveys')
                ->form([
                    Select::make('values')
                        ->label('Surveys')
                        ->options(fn (): array => Survey::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['survey_ids' => $data['values'] ?? []])),

            Filter::make('groups')
                ->form([$this->groupSelect('values', 'Groups')])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['group_ids' => $data['values'] ?? []])),

            Filter::make('counties')
                ->form([
                    Select::make('values')
                        ->label('Counties')
                        ->options(fn (): array => County::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['county_ids' => $data['values'] ?? []])),

            Filter::make('directions')
                ->form([
                    Select::make('values')
                        ->label('Direction')
                        ->options(['add' => 'Credits added', 'subtract' => 'Credits utilized'])
                        ->multiple(),
                ])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['directions' => $data['values'] ?? []])),

            Filter::make('transaction_types')
                ->form([
                    Select::make('values')
                        ->label('Activity type')
                        ->options([
                            'load' => 'Credit loads',
                            'sms_sent' => 'Outbound SMS',
                            'sms_received' => 'Inbound SMS',
                        ])
                        ->multiple(),
                ])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['transaction_types' => $data['values'] ?? []])),

            Filter::make('channels')
                ->form([
                    Select::make('values')
                        ->label('Channel')
                        ->options(fn (): array => $this->channelOptions())
                        ->multiple(),
                ])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['channels' => $data['values'] ?? []])),

            Filter::make('message_scope')
                ->form([
                    Select::make('value')
                        ->label('Outbound message scope')
                        ->options([
                            'all' => 'All outbound messages',
                            'reminders' => 'Reminder messages only',
                            'standard' => 'Standard messages only',
                        ])
                        ->default('all'),
                ])
                ->query(fn (Builder $query, array $data): Builder => app(CreditUtilizationReportService::class)
                    ->applyFilters($query, ['message_scope' => $data['value'] ?? 'all'])),
        ];
    }

    private function exportFilterForm(): array
    {
        return [
            Section::make('Reporting period')
                ->description('Leave both dates blank for all available history.')
                ->schema([
                    DatePicker::make('date_from')
                        ->label('From date')
                        ->native(false)
                        ->maxDate(fn ($get) => $get('date_to') ?: today()),
                    DatePicker::make('date_to')
                        ->label('Through date')
                        ->native(false)
                        ->maxDate(today())
                        ->minDate(fn ($get) => $get('date_from')),
                ])
                ->columns(2),

            Section::make('Attribution')
                ->description('Narrow the report by the survey and member context attached to each credit transaction.')
                ->schema([
                    Select::make('survey_ids')
                        ->label('Surveys')
                        ->options(fn (): array => Survey::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    $this->groupSelect('group_ids', 'Groups'),
                    Select::make('county_ids')
                        ->label('Counties')
                        ->options(fn (): array => County::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ])
                ->columns(3),

            Section::make('Credit activity')
                ->schema([
                    Select::make('directions')
                        ->label('Direction')
                        ->options(['add' => 'Credits added', 'subtract' => 'Credits utilized'])
                        ->multiple(),
                    Select::make('transaction_types')
                        ->label('Activity type')
                        ->options([
                            'load' => 'Credit loads',
                            'sms_sent' => 'Outbound SMS',
                            'sms_received' => 'Inbound SMS',
                        ])
                        ->multiple(),
                    Select::make('channels')
                        ->label('Channels')
                        ->options(fn (): array => $this->channelOptions())
                        ->multiple(),
                    Select::make('message_scope')
                        ->label('Outbound message scope')
                        ->options([
                            'all' => 'All outbound messages',
                            'reminders' => 'Reminder messages only',
                            'standard' => 'Standard messages only',
                        ])
                        ->default('all')
                        ->required(),
                ])
                ->columns(2),
        ];
    }

    private function groupSelect(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Group::query()
                ->where('name', 'like', "%{$search}%")
                ->orderBy('name')
                ->limit(50)
                ->pluck('name', 'id')
                ->all())
            ->getOptionLabelsUsing(fn (array $values): array => Group::query()
                ->whereIn('id', $values)
                ->pluck('name', 'id')
                ->all());
    }

    private function channelOptions(): array
    {
        return SMSInbox::query()
            ->whereNotNull('channel')
            ->where('channel', '!=', '')
            ->distinct()
            ->orderBy('channel')
            ->pluck('channel')
            ->mapWithKeys(fn (string $channel): array => [strtolower($channel) => strtoupper($channel)])
            ->all();
    }

    private function currentReportFilters(): array
    {
        return app(CreditUtilizationReportService::class)->normalizeFilters([
            'date_from' => data_get($this->tableFilters, 'date_range.from'),
            'date_to' => data_get($this->tableFilters, 'date_range.until'),
            'survey_ids' => data_get($this->tableFilters, 'surveys.values', []),
            'group_ids' => data_get($this->tableFilters, 'groups.values', []),
            'county_ids' => data_get($this->tableFilters, 'counties.values', []),
            'directions' => data_get($this->tableFilters, 'directions.values', []),
            'transaction_types' => data_get($this->tableFilters, 'transaction_types.values', []),
            'channels' => data_get($this->tableFilters, 'channels.values', []),
            'message_scope' => data_get($this->tableFilters, 'message_scope.value', 'all'),
        ]);
    }

    private function queueReport(array $data): void
    {
        $userId = auth()->id();
        abort_unless($userId, 403);

        $filters = app(CreditUtilizationReportService::class)->normalizeFilters($data);
        $uuid = (string) Str::uuid();
        $fileName = 'echo_net_africa_credit_utilization_'.now()->format('Y_m_d_His').'.xlsx';

        $report = CreditReportExport::query()->create([
            'uuid' => $uuid,
            'user_id' => $userId,
            'status' => CreditReportExport::STATUS_QUEUED,
            'filters' => $filters,
            'disk' => 'local',
            'file_path' => "private/credit-reports/{$userId}/{$uuid}.xlsx",
            'file_name' => $fileName,
        ]);

        GenerateCreditUtilizationReportJob::dispatch($report->id)->afterCommit();

        Notification::make()
            ->title('Credit report queued')
            ->body('The Excel workbook is being generated in the background. Its status will appear in Recent exports below.')
            ->success()
            ->send();
    }
}
