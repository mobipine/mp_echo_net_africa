<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;

class DependantsRelationManager extends RelationManager
{
    protected static string $relationship = 'dependants';
    protected static ?string $recordTitleAttribute = 'name';
    protected static bool $showOnView = true;


    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\TextInput::make('relationship')->required(),
            Forms\Components\DatePicker::make('dob')->label('Date of Birth'),
            Forms\Components\Select::make('gender')->options([
                'male' => 'Male',
                'female' => 'Female',
            ]),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('relationship')->sortable(),
            Tables\Columns\TextColumn::make('dob')->date(),
            Tables\Columns\TextColumn::make('gender'),
        ])->headerActions([
            Tables\Actions\CreateAction::make(),
        ])->actions([
            Tables\Actions\ViewAction::make(),

            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
