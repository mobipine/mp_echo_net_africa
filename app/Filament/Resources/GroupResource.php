<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\SurveyRelationManagerResource\RelationManagers\SurveysRelationManager;
use App\Models\Group;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                \Filament\Forms\Components\TextInput::make('township')->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
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
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            SurveysRelationManager::class,
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
