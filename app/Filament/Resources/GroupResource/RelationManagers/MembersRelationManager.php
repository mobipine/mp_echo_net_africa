<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Models\Official;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Forms\Components\TextInput::make('group_id')
                //     ->required()
                //     ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('group_id')
            ->columns([
                Tables\Columns\TextColumn::make('group_id'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('phone'),
                Tables\Columns\TextColumn::make('national_id'),
                Tables\Columns\TextColumn::make('gender'),
                Tables\Columns\TextColumn::make('dob'),
                Tables\Columns\TextColumn::make('marital_status'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('makeOfficial')
                    ->label('Make Official')
                    ->icon('heroicon-o-arrow-right')
                    ->form([
                        Forms\Components\Select::make('official_position_id')
                            ->label('Position')
                            ->options(\App\Models\OfficialPosition::pluck('position_name', 'id'))
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
                    ])
                    ->action(function (array $data, Tables\Actions\Action $action, $record): void {
                        Official::create([
                            'group_id' => $this->ownerRecord->id,
                            'member_id' => $record->id,
                            'official_position_id' => $data['official_position_id'],
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Member designated as official')
                            ->body('The member has been successfully assigned a official position.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record) => !Official::where('member_id', $record->id)->where('group_id', $this->ownerRecord->id)->exists()),
                Tables\Actions\Action::make('removeOfficial')
                    ->label('Remove Official')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->action(function ($record): void {
                        Official::where('member_id', $record->id)
                            ->where('group_id', $this->ownerRecord->id)
                            ->delete();

                        \Filament\Notifications\Notification::make()
                            ->title('Member removed as official')
                            ->body('The member has been successfully removed from their official position.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record) => Official::where('member_id', $record->id)->where('group_id', $this->ownerRecord->id)->exists()),
        
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public function canCreate(): bool
    {
        return false;
    }
    
}
