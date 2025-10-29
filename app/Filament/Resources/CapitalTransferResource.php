<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CapitalTransferResource\Pages;
use App\Models\Group;
use App\Models\OrganizationGroupCapitalTransfer;
use App\Services\CapitalTransferService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\RawJs;
use Filament\Notifications\Notification;

class CapitalTransferResource extends Resource
{
    protected static ?string $model = OrganizationGroupCapitalTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    
    protected static ?string $navigationLabel = 'Capital Transfers';
    
    // protected static ?string $navigationGroup = 'Group Management';
    
    protected static ?int $navigationSort = 2;

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
                    ->native(false),
                
                Forms\Components\Select::make('transfer_type')
                    ->label('Transfer Type')
                    ->options([
                        'advance' => 'Capital Advance (Org → Group)',
                        'return' => 'Capital Return (Group → Org)',
                    ])
                    ->required()
                    ->native(false)
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set, $get) {
                        if ($state === 'advance') {
                            $set('purpose', '');
                        }
                    }),
                
                Forms\Components\TextInput::make('amount')
                    ->label('Amount (KES)')
                    ->required()
                    ->numeric()
                    ->required()
                    ->live(onBlur: true)
                   
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->minValue(1)
                    ->prefix('KES'),
                
                Forms\Components\DatePicker::make('transfer_date')
                    ->label('Transfer Date')
                    ->default(now())
                    ->required(),
                
                Forms\Components\TextInput::make('reference_number')
                    ->label('Reference Number')
                    ->placeholder('Auto-generated if left empty'),
                
                Forms\Components\Textarea::make('purpose')
                    ->label('Purpose')
                    ->visible(fn ($get) => $get('transfer_type') === 'advance')
                    ->maxLength(500)
                    ->rows(3),
                
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->maxLength(500)
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('group.name')
                    ->label('Group')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\BadgeColumn::make('transfer_type')
                    ->label('Type')
                    ->colors([
                        'success' => 'advance',
                        'warning' => 'return',
                    ])
                    ->formatStateUsing(fn ($state) => $state === 'advance' ? 'Advance' : 'Return'),
                
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money('KES')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('transfer_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Reference')
                    ->searchable(),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'completed',
                        'danger' => 'rejected',
                    ]),
                
                Tables\Columns\TextColumn::make('approver.name')
                    ->label('Approved By')
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('transfer_type')
                    ->options([
                        'advance' => 'Advance',
                        'return' => 'Return',
                    ]),
                
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'completed' => 'Completed',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // No bulk actions for safety
            ])
            ->defaultSort('transfer_date', 'desc');
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
            'index' => Pages\ListCapitalTransfers::route('/'),
            'create' => Pages\CreateCapitalTransfer::route('/create'),
            'view' => Pages\ViewCapitalTransfer::route('/{record}'),
        ];
    }
}

