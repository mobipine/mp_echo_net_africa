<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaccoProductResource\Pages;
use App\Models\SaccoProduct;
use App\Models\SaccoProductType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SaccoProductResource extends Resource
{
    protected static ?string $model = SaccoProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    
    protected static ?string $cluster = \App\Filament\Clusters\SaccoManagement::class;
    
    protected static ?int $navigationSort = 1;
    
    protected static ?string $navigationLabel = 'SACCO Products';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Product Information')
                    ->schema([
                        Forms\Components\Select::make('product_type_id')
                            ->label('Product Type')
                            ->options(SaccoProductType::pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->placeholder('e.g., MAIN_SAVINGS')
                            ->helperText('Unique code for this product'),
                        
                        Forms\Components\Textarea::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])->columns(3),
                
                Forms\Components\Section::make('Status & Availability')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active products can be used'),
                        
                        Forms\Components\Toggle::make('is_mandatory')
                            ->label('Mandatory')
                            ->helperText('All members must subscribe to mandatory products'),
                        
                        Forms\Components\DatePicker::make('start_date')
                            ->label('Available From'),
                        
                        Forms\Components\DatePicker::make('end_date')
                            ->label('Available Until'),
                    ])->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                
                Tables\Columns\TextColumn::make('productType.name')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Member Savings' => 'success',
                        'Subscription Product' => 'info',
                        'One-Time Fee' => 'warning',
                        'Penalty/Fine' => 'danger',
                        default => 'gray',
                    }),
                
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                
                Tables\Columns\IconColumn::make('is_mandatory')
                    ->label('Mandatory')
                    ->boolean(),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type_id')
                    ->label('Product Type')
                    ->options(SaccoProductType::pluck('name', 'id')),
                
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('View & Map Accounts'),
                Tables\Actions\EditAction::make(),
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
            \App\Filament\Resources\SaccoProductResource\RelationManagers\ChartOfAccountsRelationManager::class,
            \App\Filament\Resources\SaccoProductResource\RelationManagers\ProductAttributeValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSaccoProducts::route('/'),
            'create' => Pages\CreateSaccoProduct::route('/create'),
            'view' => Pages\ViewSaccoProduct::route('/{record}'),
            'edit' => Pages\EditSaccoProduct::route('/{record}/edit'),
        ];
    }
}

