<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers;
use App\Models\Group;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    
    protected static ?string $navigationLabel = 'Groups';
    
    // protected static ?string $navigationGroup = 'Group Management';
    
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('phone_number')
                            ->tel()
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('registration_number')
                            ->maxLength(100),
                        
                        Forms\Components\DatePicker::make('formation_date')
                            ->label('Formation Date'),
                    ])
                    ->columns(2),
                
                Forms\Components\Section::make('Location')
                    ->schema([
                        Forms\Components\TextInput::make('county')
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('sub_county')
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('ward')
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('township')
                            ->maxLength(255),
                        
                        Forms\Components\Textarea::make('address')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('registration_number')
                    ->label('Reg No.')
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('bank_balance')
                    ->label('Bank Balance')
                    ->money('KES')
                    ->getStateUsing(fn (Group $record) => $record->bank_balance),
                
                Tables\Columns\TextColumn::make('total_capital_advanced')
                    ->label('Capital Advanced')
                    ->money('KES')
                    ->getStateUsing(fn (Group $record) => $record->total_capital_advanced),
                
                Tables\Columns\TextColumn::make('net_capital_outstanding')
                    ->label('Net Outstanding')
                    ->money('KES')
                    ->getStateUsing(fn (Group $record) => $record->net_capital_outstanding),
                
                Tables\Columns\TextColumn::make('formation_date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('county')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\GroupAccountsRelationManager::class,
            RelationManagers\MembersRelationManager::class,
            RelationManagers\LoansRelationManager::class,
            RelationManagers\TransactionsRelationManager::class,
            RelationManagers\CapitalTransfersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/create'),
            'view' => Pages\ViewGroup::route('/{record}'),
            'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }
}
