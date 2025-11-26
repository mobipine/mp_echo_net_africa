<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use App\Models\Official;

class OfficialsRelationManager extends RelationManager
{
    protected static string $relationship = 'officials';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->relationship(
                        'member',
                        'name',
                        fn ($query) => $query->where('group_id', $this->ownerRecord->id)
                    )
                    ->required()
                    ->searchable()
                    ->preload(),

                Forms\Components\Select::make('official_position_id')
                    ->label('Position')
                    ->relationship('position', 'position_name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->unique(
                        'officials',
                        'official_position_id',
                        modifyRuleUsing: fn (Unique $rule) =>
                            $rule->where('group_id', $this->ownerRecord->id)
                                 ->where('is_active', true)
                    )
                    ->validationMessages([
                        'unique' => 'This position is currently held by an active official.',
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
            ->modifyQueryUsing(fn ($query) => $query->where('is_active', true))
            ->columns([
                Tables\Columns\TextColumn::make('member.name')
                    ->label('Member')
                    ->sortable(),

                Tables\Columns\TextColumn::make('position.position_name')
                    ->label('Position')
                    ->sortable(),
            ])

            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data, $livewire) {
                        $groupId = $livewire->ownerRecord->id;
                        $positionId = is_array($data['official_position_id'])
                            ? ($data['official_position_id']['id'] ?? null)
                            : $data['official_position_id'];
                        if (! $positionId) {
                            return $data;
                        }

                        $activeExists = Official::where('group_id', $groupId)
                            ->where('official_position_id', $positionId)
                            ->where('is_active', true)
                            ->exists();

                        if ($activeExists) {
                            throw ValidationException::withMessages([
                                'official_position_id' => 'This position is already taken by an active official.',
                            ]);
                        }
                        return $data;
                    }),

                // Past Officials modal
                Tables\Actions\Action::make('viewPastOfficials')
                    ->label('Past Officials')
                    ->modalHeading('Past Officials')
                    ->modalWidth('4xl')
                    ->icon('heroicon-m-clock')
                    ->action(fn () => null)
                    ->modalContent(function () {
                        $past = Official::with('member', 'position')
                            ->where('group_id', $this->ownerRecord->id)
                            ->where('is_active', false)
                            ->get();

                        return view('filament.modals.past-officials', [
                            'pastOfficials' => $past
                        ]);
                    }),
            ])

            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('Remove')
                    ->label('Remove Official')
                    ->icon('heroicon-m-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->is_active = false;
                        $record->left_at = now();
                        $record->save();
                    })

            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
