<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\GroupResource\RelationManagers\OfficialsRelationManager;
use App\Filament\Resources\SurveyRelationManagerResource\RelationManagers\SurveysRelationManager;
use App\Models\CountyENAStaff;
use App\Models\Group;
use App\Models\LocalImplementingPartner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Illuminate\Support\Facades\Storage;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use App\Imports\GroupsImport;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Notifications\Notification;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\TextInput::make('name')->required()->maxLength(255),
                \Filament\Forms\Components\TextInput::make('email')->email()->maxLength(255),
                \Filament\Forms\Components\TextInput::make('phone_number')->tel()->maxLength(20),
                \Filament\Forms\Components\Select::make('county')
                    // ->options(config('counties.code'))
                    //for the options get the counties from the config file and loop through taking only the "county" key
                    ->options(fn() => collect(config('counties'))->mapWithKeys(fn($county) => [$county['code'] => $county['county']]))
                    //on state update, set the sub_county options
                    ->afterStateUpdated(function (callable $set, $state) {
                        // self::updateSubCounties($set, $state);
                        // Find the first staff member for the selected county code
                        $staff = CountyENAStaff::where('county', $state)->first();

                        // If a staff member is found, set the value of the staff select field
                        if ($staff) {
                            $set('county_ENA_staff_id', $staff->id);
                        } else {
                            // Otherwise, clear the staff select field
                            $set('county_ENA_staff_id', null);
                        }

                        // Also clear the sub_county field to prevent data inconsistency
                        $set('sub_county', null); 
                    })
                    ->native(false)
                    ->searchable()
                    ->reactive()
                    ->required(),
                \Filament\Forms\Components\Select::make('sub_county')
                    // ->options(fn (callable $get) => config('sub_counties')[$get('county')] ?? [])
                    // ->options(fn (callable $get) => (collect(config('counties'))->firstWhere('code', $get('county'))['subCounties']) ?? [])
                    ->options(function (callable $get) {
                        $counties = config('counties');

                        $selectedCountyCode = $get('county');
                        if (!$selectedCountyCode) {
                            return [];
                        }

                        $county = collect($counties)->firstWhere('code', $selectedCountyCode);
                        $sub_counties = $county['subCounties'] ?? "";
                        $sub_counties_array = explode(',', $sub_counties);
                        // dd($sub_counties_array);

                        return collect($sub_counties_array)->mapWithKeys(function ($sub_county) {
                            return [$sub_county => $sub_county];
                        });
                    })
                    ->native(false)
                    ->searchable()
                    ->required(),
                \Filament\Forms\Components\Textarea::make('address')->maxLength(500),
                \Filament\Forms\Components\TextInput::make('township')->label('Village')->maxLength(255),
                \Filament\Forms\Components\TextInput::make('ward')->label('Ward')->maxLength(255),
                \Filament\Forms\Components\Select::make('local_implementing_partner_id')
                    ->label('Local Implementing Partner')
                    ->options(LocalImplementingPartner::all()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->nullable(),
                \Filament\Forms\Components\Select::make('county_ENA_staff_id')
                    ->label('County ENA Staff')
                    ->options(function (callable $get) {
                        $selectedCounty = $get('county');
                        
                        if (!$selectedCounty) {
                            return [];
                        }

                        return CountyENAStaff::where('county', $selectedCounty)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->nullable(),
                \Filament\Forms\Components\FileUpload::make('group_certificate')
                ->label('Upload Certificate')
                ->disk('public') // Specify the disk where the file will be stored
                ->directory('documents') // The directory within the disk
               ->acceptedFileTypes([
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'image/png',
                    'image/jpeg',
                ])
                ->storeFileNamesIn('original_filename') // Optional: store original filename
                ->preserveFileNames() // Optional: keep original filename
                ->required(), // Make the field required
        
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                Action::make('import')
                    ->label('Import Groups')
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
                        
                        Excel::import(new GroupsImport, $filePath);
                        
                        // Clean up the uploaded file
                        Storage::disk('local')->delete($data['file']);
                        
                        // Get import results from session
                        $results = session('import_results', ['imported' => 0, 'skipped' => 0]);
                        
                        // Show success notification with results
                        Notification::make()
                            ->title('Import Completed')
                            ->body("Successfully imported {$results['imported']} groups. Skipped {$results['skipped']} rows due to errors or duplicates.")
                            ->success()
                            ->send();
                    })
                    ->after(function () {
                        // Clear the session data
                        session()->forget('import_results');
                    })
                ])
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                \Filament\Tables\Columns\TextColumn::make('email')->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('county')
                    ->label('County')
                    ->formatStateUsing(function ($state) {
                        // Map county code to county name
                        $counties = config('counties');
                        $county = collect($counties)->firstWhere('code', $state);
                        return $county['county'] ?? $state;
                    })
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Enable searching by both code and name
                        $counties = config('counties');
                        $matchingCodes = collect($counties)
                            ->filter(fn($county) => stripos($county['county'], $search) !== false)
                            ->pluck('code')
                            ->toArray();
                        
                        return $query->whereIn('county', $matchingCodes)
                                    ->orWhere('county', 'like', "%{$search}%");
                    })
                    ->toggleable(isToggledHiddenByDefault:true),
            
                \Filament\Tables\Columns\TextColumn::make('sub_county')->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('ward')->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('localImplementingPartner.name')->toggleable(isToggledHiddenByDefault:true),
                \Filament\Tables\Columns\TextColumn::make('countyENAStaff.name')->label('County ENA Staff')->toggleable(isToggledHiddenByDefault:true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
            MembersRelationManager::class,
            SurveysRelationManager::class,
            OfficialsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/create'),
            'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }
}
