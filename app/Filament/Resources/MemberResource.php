<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Imports\MembersImport;
use App\Imports\MembersImportPreview;
use App\Models\Member;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use Illuminate\Validation\ValidationException;
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Only apply restriction for staff, not admins
        if (auth()->user()->hasRole('county_staff')) {
            $query->where('county_id', auth()->user()->county_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Select::make('groups')
                    ->label('Groups')
                    ->multiple()
                    ->relationship('groups', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required()
                    ->helperText('Select one or more groups this member belongs to'),
                \Filament\Forms\Components\TextInput::make('name')->required()->placeholder('e.g. John Doe')->hint("As it appears on the ID"),
                \Filament\Forms\Components\TextInput::make('account_number')
                    ->required()
                    ->placeholder('e.g. ACC-0001')
                    ->unique(ignoreRecord: true)
                    ->maxLength(20)
                    ->minLength(5)
                    ->readOnly()
                    ->visibleOn('edit'),
                \Filament\Forms\Components\TextInput::make('email')->email()->placeholder('john.doe@example.com')->nullable()->maxLength(255)->unique(ignoreRecord: true),
                \Filament\Forms\Components\TextInput::make('phone')->placeholder('e.g. 0712345678')->maxLength(10)->minLength(10),
                \Filament\Forms\Components\TextInput::make('national_id')->required()->placeholder('e.g. 11111111')
                    ->unique(ignoreRecord: true)
                    ->maxLength(9)
                    ->minLength(7)
                    ->numeric()
                    ->hint("As it appears on the ID"),
                \Filament\Forms\Components\Select::make('gender')
                ->native(false)
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                    ]),
                Toggle::make('is_disabled')
                    ->label('Is Disabled')
                    ->reactive()
                    ->inline(false),
                Forms\Components\Select::make('county_id')
                    ->label('County')
                    ->relationship(
                        name: 'county',
                        titleAttribute: 'name',
                        modifyQueryUsing: function ($query) {
                            if (auth()->user()->hasRole('county_staff')) {
                                $query->where('id', auth()->user()->county_id);
                            }
                        }
                    )
                    ->searchable()
                    ->native(false)
                    ->required(),


                Toggle::make('consent')
                    ->label("Consent"),

                \Filament\Forms\Components\TextInput::make('disability')
                    ->label('Type of Disability')
                    ->placeholder('e.g. Visual impairment')
                    ->visible(fn (callable $get) => $get('is_disabled') === true)
                    ->required(fn (callable $get) => $get('is_disabled') === true)
                    ->maxLength(255),
                \Filament\Forms\Components\DatePicker::make('dob')->label('Date of Birth')
                ->hint("As it appears on the ID"),
                \Filament\Forms\Components\Select::make('marital_status')
                ->native(false)
                    ->options([
                        'single' => 'Single',
                        'married' => 'Married',
                    ]),
                \Filament\Forms\Components\FileUpload::make('profile_picture')
                    ->image()
                    ->imageEditor()
                    ->imageEditorAspectRatios([
                        '16:9',
                        '4:3',
                        '1:1',
                    ])
                    ->directory('profile-pictures')
                    ->nullable()
                    ->visibility('public')
                    ->enableDownload()
                    ->enableOpen(),
                Toggle::make('is_active',)

            ]);
    }
    //    use Filament\Forms\Validation\ValidationException;


    public static function validateDob(array $data): void
    {
        Log::info("validating date");
        if (!isset($data['dob'])) {
            return;
        }

        $dob = \Carbon\Carbon::parse($data['dob']);
        $age = $dob->diffInYears(now());

        $isDisabled = $data['is_disabled'] ?? false;

        if ($isDisabled && ($age < 18 || $age > 40)) {
            throw ValidationException::withMessages([
                'dob' => 'Persons with disabilities must be between 18 and 40 years old.',
            ]);
        }

        if (!$isDisabled && ($age < 18 || $age > 35)) {
            throw ValidationException::withMessages([
                'dob' => 'Standard members must be between 18 and 35 years old.',
            ]);
        }
    }

    /**
     * Run a synchronous dry-run of the members import and return the summary.
     * Cached briefly so navigating the wizard / re-renders don't re-parse the file.
     *
     * @param  string  $absolutePath  Absolute filesystem path to the uploaded file.
     * @param  string|null  $extension  Original file extension, used to pick the reader.
     * @return array<string,mixed>
     */
    public static function analyzeImportFile(string $absolutePath, ?string $extension = null): array
    {
        if (blank($absolutePath) || !is_file($absolutePath)) {
            return ['total' => 0, 'unreadable' => true];
        }

        $readerType = match ($extension) {
            'csv', 'txt' => \Maatwebsite\Excel\Excel::CSV,
            'xls' => \Maatwebsite\Excel\Excel::XLS,
            'xlsx', 'xlsm' => \Maatwebsite\Excel\Excel::XLSX,
            default => null, // let the library auto-detect
        };

        $cacheKey = 'members_import_preview:' . md5($absolutePath . '|' . filemtime($absolutePath));

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($absolutePath, $readerType, $extension) {
            $previewId = (string) Str::uuid();

            Log::info('[MembersImport] Dry-run preview started', [
                'preview_id' => $previewId,
                'user_id' => auth()->id(),
                'file' => basename($absolutePath),
                'extension' => $extension,
                'size_bytes' => @filesize($absolutePath) ?: null,
            ]);

            try {
                $preview = new MembersImportPreview();
                Excel::import($preview, $absolutePath, null, $readerType);
                $result = $preview->result();
            } catch (\Throwable $e) {
                Log::error('[MembersImport] Dry-run preview failed', [
                    'preview_id' => $previewId,
                    'user_id' => auth()->id(),
                    'file' => basename($absolutePath),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            // If nothing was found, capture what the file actually looks like so the
            // user can see whether headers are on the wrong row / wrong sheet.
            if (($result['total'] ?? 0) === 0) {
                try {
                    $raw = Excel::toArray(new \stdClass(), $absolutePath, null, $readerType);
                    $firstSheet = $raw[0] ?? [];
                    $result['diagnostics'] = [
                        'sheet_count' => count($raw),
                        'first_rows' => array_slice($firstSheet, 0, 3),
                    ];
                } catch (\Throwable $e) {
                    $result['diagnostics'] = ['error' => $e->getMessage()];
                }

                Log::warning('[MembersImport] Dry-run preview found no data rows', [
                    'preview_id' => $previewId,
                    'user_id' => auth()->id(),
                    'file' => basename($absolutePath),
                    'diagnostics' => $result['diagnostics'] ?? null,
                ]);
            } else {
                Log::info('[MembersImport] Dry-run preview completed', [
                    'preview_id' => $previewId,
                    'user_id' => auth()->id(),
                    'file' => basename($absolutePath),
                    'total' => $result['total'] ?? 0,
                    'will_create' => $result['create'] ?? 0,
                    'will_update' => $result['update'] ?? 0,
                    'errors' => $result['errors'] ?? 0,
                    'with_warnings' => $result['with_warnings'] ?? 0,
                    'new_groups' => $result['new_groups'] ?? [],
                ]);
            }

            return $result;
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                Action::make('import')
                    ->label('Import Members')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->modalWidth('4xl')
                    ->modalSubmitActionLabel('Import')
                    ->steps([
                        Step::make('Upload File')
                            ->description('Choose your Excel/CSV file')
                            ->icon('heroicon-o-arrow-up-tray')
                            ->schema([
                                Forms\Components\FileUpload::make('file')
                                    ->label('Excel File')
                                    ->acceptedFileTypes([
                                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                        'application/vnd.ms-excel',
                                        'text/csv'
                                    ])
                                    ->required()
                                    ->disk('local')
                                    ->directory('imports')
                                    ->helperText('Make sure the first row contains the column headers (group_name, national_id, name_of_participant, phone_no, gender, year).'),
                            ]),
                        Step::make('Preview')
                            ->description('Review before importing')
                            ->icon('heroicon-o-magnifying-glass')
                            ->schema([
                                Placeholder::make('preview')
                                    ->hiddenLabel()
                                    ->content(function (Get $get): HtmlString {
                                        $file = $get('file');

                                        // FileUpload state is [uuid => value]; the value is a
                                        // TemporaryUploadedFile while editing, or a stored path string.
                                        $value = is_array($file) ? collect($file)->first() : $file;

                                        if (blank($value)) {
                                            return new HtmlString('<div class="text-sm text-gray-500 dark:text-gray-400">Upload a file in the previous step to see a preview.</div>');
                                        }

                                        if ($value instanceof TemporaryUploadedFile) {
                                            $absolutePath = $value->getRealPath();
                                            $extension = strtolower($value->getClientOriginalExtension());
                                        } else {
                                            $absolutePath = Storage::disk('local')->path($value);
                                            $extension = strtolower(pathinfo((string) $value, PATHINFO_EXTENSION));
                                        }

                                        return new HtmlString(
                                            view('filament.imports.members-preview', [
                                                'preview' => static::analyzeImportFile($absolutePath, $extension),
                                            ])->render()
                                        );
                                    }),
                            ]),
                    ])
                    ->action(function (array $data) {
                        $importId = (string) Str::uuid();
                        $filePath = Storage::disk('local')->path($data['file']);

                        Log::info('[MembersImport] Import submitted from UI', [
                            'import_id' => $importId,
                            'user_id' => auth()->id(),
                            'stored_path' => $data['file'],
                            'size_bytes' => @filesize($filePath) ?: null,
                        ]);

                        try {
                            Excel::import(new MembersImport($importId), $filePath);

                            Log::info('[MembersImport] Import handed off to Excel (queued/processed)', [
                                'import_id' => $importId,
                                'user_id' => auth()->id(),
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('[MembersImport] Import failed to start', [
                                'import_id' => $importId,
                                'user_id' => auth()->id(),
                                'error' => $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title('Import Failed')
                                ->body('The file could not be imported: ' . $e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        // Clean up the uploaded file
                        Storage::disk('local')->delete($data['file']);
                        Log::info('[MembersImport] Uploaded file cleaned up', [
                            'import_id' => $importId,
                            'stored_path' => $data['file'],
                        ]);

                        // Get import results from session
                        $results = session('import_results', ['imported' => 0, 'skipped' => 0,'updated' => 0]);

                        Log::info('[MembersImport] Import results reported to user', [
                            'import_id' => $importId,
                            'user_id' => auth()->id(),
                            'results' => $results,
                        ]);

                        // Show success notification with results
                        Notification::make()
                            ->title('Import Completed')
                            ->body("Successfully imported {$results['imported']} members. Updated {$results['updated']} rows. Skipped {$results['skipped']} rows due to errors or duplicates.")
                            ->success()
                            ->send();
                    })
                    ->after(function () {
                        // Clear the session data
                        session()->forget('import_results');
                    })
            ])
            ->columns([
                // \Filament\Tables\Columns\ImageColumn::make('profile_picture')->label('pfp')->circular()
                //     ->disk('public')
                //     ->size(40)
                //     ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('stage')->sortable()->toggleable(isToggledHiddenByDefault:true)->searchable(),
                \Filament\Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('groups.name')
                    ->label('Groups')
                    ->badge()
                    ->separator(',')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('email')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('phone')->sortable()->toggleable(isToggledHiddenByDefault:true)->searchable(),
                \Filament\Tables\Columns\TextColumn::make('national_id')->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('gender')->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('dob')->date()->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('marital_status')->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault:true),
                Tables\Columns\TextColumn::make('county.name')
                    ->label('County')
                    ->searchable()
                    ->sortable(),

            ])
            ->filters([
                //
            ])
            ->actions([
                // Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->color('gray')->icon('heroicon-o-eye')->label('View'),
                Tables\Actions\DeleteAction::make(),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                     ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                            
                        ])
                    ->label('Export to Excel'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DependantsRelationManager::class,
            RelationManagers\KycDocumentRelationManager::class,
            RelationManagers\EmailInboxesRelationManager::class,
            RelationManagers\SmsInboxesRelationManager::class,
            RelationManagers\SurveyResponsesRelationManager::class,
            
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/view'),
            // 'edit' => Pages\EditMemberTabbed::route('/{record}/edit'),

        ];
    }
}
