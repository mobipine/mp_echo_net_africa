<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Imports\MembersImport;
use App\Models\Member;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Select::make('group_id')
                ->native(false)
                    ->label('Group')
                    ->relationship('group', 'name')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('name')->required()->placeholder('e.g. John Doe'),
                \Filament\Forms\Components\TextInput::make('account_number')
                    ->required()
                    ->placeholder('e.g. ACC-0001')
                    ->unique(ignoreRecord: true)
                    ->maxLength(20)
                    ->minLength(5)
                    ->readOnly()
                    ->visibleOn('edit'),
                \Filament\Forms\Components\TextInput::make('email')->email()->placeholder('john.doe@example.com')->required()->maxLength(255)->unique(ignoreRecord: true),
                \Filament\Forms\Components\TextInput::make('phone')->placeholder('e.g. 0712345678'),
                \Filament\Forms\Components\TextInput::make('national_id')->required()->placeholder('e.g. 11111111')
                    ->unique(ignoreRecord: true)
                    ->maxLength(8)
                    ->minLength(7)
                    ->numeric(),
                \Filament\Forms\Components\Select::make('gender')
                ->native(false)
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                    ]),
                \Filament\Forms\Components\DatePicker::make('dob')->label('Date of Birth')
                ->native(false),
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

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                Action::make('import')
                    ->label('Import Members')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
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
                            ->helperText('Download sample template first to ensure correct format')
                    ])
                    ->action(function (array $data) {
                        $filePath = Storage::disk('local')->path($data['file']);
                        
                        Excel::import(new MembersImport, $filePath);
                        
                        // Clean up the uploaded file
                        Storage::disk('local')->delete($data['file']);
                        
                        // Get import results from session
                        $results = session('import_results', ['imported' => 0, 'skipped' => 0]);
                        
                        // Show success notification with results
                        Notification::make()
                            ->title('Import Completed')
                            ->body("Successfully imported {$results['imported']} members. Skipped {$results['skipped']} rows due to errors or duplicates.")
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
                \Filament\Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('group.name')->label('Group')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('email')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('phone')->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('national_id')->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('gender')->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('dob')->date()->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('marital_status')->sortable()->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault:true),
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
