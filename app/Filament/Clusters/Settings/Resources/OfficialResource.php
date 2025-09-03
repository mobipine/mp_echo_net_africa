<?php

namespace App\Filament\Clusters\Settings\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Clusters\Settings\Resources\OfficialResource\Pages;
use App\Filament\Clusters\Settings\Resources\OfficialResource\RelationManagers;
use App\Models\Official;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;

class OfficialResource extends Resource
{
    protected static ?string $model = Official::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $cluster = Settings::class;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                 Forms\Components\Select::make('group_id')
                    ->label('Group')
                    ->relationship('group', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live(),
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->reactive()
                    ->relationship('member', 'name', function (Builder $query, Forms\Get $get) {
                        $groupId = $get('group_id');
                        if ($groupId) {
                            $query->where('group_id', $groupId);
                        }
                    })
                    ->required()
                    ->searchable()
                    ->preload()
                    ->hidden(fn (Forms\Get $get) => !$get('group_id')),
                Forms\Components\Select::make('official_position_id')
                    ->label('Position')
                    ->relationship('position', 'position_name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->unique('officials', 'official_position_id', ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule->where('group_id', $get('group_id'))
                    )
                    ->validationMessages([
                        'unique' => 'This position is already taken by another member in this group.',
                    ])
                    ->createOptionForm([
                        Forms\Components\TextInput::make('position_name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                "unique" => 'Position already exists'
                            ]),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $position = \App\Models\OfficialPosition::create($data);
                        return $position->id;
                    }),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group.name')
                    ->label('Group')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('position.position_name')
                    ->label('Position')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->label('Remove'),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOfficials::route('/'),
            'create' => Pages\CreateOfficial::route('/create'),
            'edit' => Pages\EditOfficial::route('/{record}/edit'),
        ];
    }
}
