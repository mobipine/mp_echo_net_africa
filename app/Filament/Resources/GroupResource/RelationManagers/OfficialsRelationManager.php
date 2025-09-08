<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class OfficialsRelationManager extends RelationManager
{
    protected static string $relationship = 'officials';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'name', fn ($query) => $query->where('group_id', $this->ownerRecord->id))
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('official_position_id')
                    ->label('Position')
                    ->relationship('position', 'position_name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->unique('officials', 'official_position_id',
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('group_id', $this->ownerRecord->id)
                    )
                    ->validationMessages([
                        'unique' => 'This position is already taken by another member in this group.',
                    ])
                    ->createOptionForm([
                        Forms\Components\TextInput::make('position_name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'Position already exists.',
                            ]),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $position = \App\Models\OfficialPosition::create($data);
                        return $position->id;
                    }),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member')
                    ->sortable(),
                Tables\Columns\TextColumn::make('position.position_name')
                    ->label('Position')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
}
