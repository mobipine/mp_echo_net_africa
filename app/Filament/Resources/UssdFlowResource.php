<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UssdFlowResource\Pages;
use App\Models\UssdFlow;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UssdFlowResource extends Resource
{
    protected static ?string $model = UssdFlow::class;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'USSD Flows';

    protected static ?string $navigationGroup = 'USSD';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Flow Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Textarea::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('flow_type')
                            ->options([
                                'loan_repayment' => 'Loan Repayment',
                                'member_search' => 'Member Search',
                                'custom' => 'Custom',
                            ])
                            ->required()
                            ->native(false)
                            ->default('loan_repayment'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Only one flow can be active at a time for each flow type.')
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get, $record) {
                                if ($state) {
                                    // Deactivate other flows of the same type
                                    $flowType = $get('flow_type');
                                    $query = UssdFlow::where('flow_type', $flowType)
                                        ->where('is_active', true);

                                    if ($record) {
                                        $query->where('id', '!=', $record->id);
                                    }

                                    $query->update(['is_active' => false]);
                                }
                            })
                            ->live(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('flow_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'loan_repayment' => 'success',
                        'member_search' => 'info',
                        'custom' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'loan_repayment' => 'Loan Repayment',
                        'member_search' => 'Member Search',
                        'custom' => 'Custom',
                        default => $state,
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active')
                    ->sortable(),

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
                Tables\Filters\SelectFilter::make('flow_type')
                    ->options([
                        'loan_repayment' => 'Loan Repayment',
                        'member_search' => 'Member Search',
                        'custom' => 'Custom',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All flows')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListUssdFlows::route('/'),
            'create' => Pages\CreateUssdFlow::route('/create'),
            'edit' => Pages\EditUssdFlow::route('/{record}/edit'),
        ];
    }
}

