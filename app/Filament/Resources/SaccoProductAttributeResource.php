<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaccoProductAttributeResource\Pages;
use App\Models\{SaccoProductAttribute, SaccoProductType};
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SaccoProductAttributeResource extends Resource
{
    protected static ?string $model = SaccoProductAttribute::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    
    // protected static ?string $navigationGroup = 'SACCO Management';
    protected static ?string $cluster = \App\Filament\Clusters\SaccoManagement::class;

    protected static ?int $navigationSort = 2;
    
    protected static ?string $navigationLabel = 'Product Attributes';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Attribute Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('slug', \Str::slug($state));
                            })
                            ->helperText('Human-readable name (e.g., "Minimum Deposit")'),
                        
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('System identifier (e.g., "minimum_deposit")'),
                        
                        Forms\Components\Select::make('type')
                            ->label('Data Type')
                            ->options([
                                'string' => 'Text (String)',
                                'integer' => 'Whole Number (Integer)',
                                'decimal' => 'Decimal Number',
                                'boolean' => 'Yes/No (Boolean)',
                                'date' => 'Date',
                                'select' => 'Dropdown (Select)',
                                'json' => 'JSON Data',
                            ])
                            ->required()
                            ->reactive()
                            ->helperText('The type of data this attribute will store'),
                        
                        Forms\Components\Textarea::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->helperText('Description of what this attribute is used for'),
                    ])->columns(2),
                
                Forms\Components\Section::make('Configuration')
                    ->schema([
                        Forms\Components\Select::make('applicable_product_types')
                            ->label('Applicable Product Types')
                            ->options(SaccoProductType::pluck('name', 'id'))
                            ->multiple()
                            ->searchable()
                            ->helperText('Which product types can use this attribute (leave empty for all)'),
                        
                        Forms\Components\TagsInput::make('options')
                            ->label('Dropdown Options')
                            ->visible(fn (callable $get) => $get('type') === 'select')
                            ->helperText('Enter options for dropdown (one per line)')
                            ->placeholder('Option 1, Option 2, Option 3'),
                        
                        Forms\Components\Textarea::make('options')
                            ->label('JSON Configuration')
                            ->visible(fn (callable $get) => $get('type') === 'json')
                            ->helperText('JSON structure or validation rules')
                            ->rows(4),
                        
                        Forms\Components\Toggle::make('is_required')
                            ->label('Required')
                            ->helperText('Must this attribute have a value?'),
                        
                        Forms\Components\TextInput::make('default_value')
                            ->label('Default Value')
                            ->maxLength(255)
                            ->helperText('Optional default value when not specified'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Bold),
                
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->copyable(),
                
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'string',
                        'success' => 'integer',
                        'warning' => 'decimal',
                        'info' => 'boolean',
                        'danger' => 'select',
                        'secondary' => 'json',
                    ]),
                
                Tables\Columns\IconColumn::make('is_required')
                    ->label('Required')
                    ->boolean(),
                
                Tables\Columns\TextColumn::make('default_value')
                    ->label('Default')
                    ->limit(30)
                    ->placeholder('None'),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'string' => 'String',
                        'integer' => 'Integer',
                        'decimal' => 'Decimal',
                        'boolean' => 'Boolean',
                        'date' => 'Date',
                        'select' => 'Select',
                        'json' => 'JSON',
                    ]),
                
                Tables\Filters\TernaryFilter::make('is_required')
                    ->label('Required Only'),
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
            ->defaultSort('name');
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
            'index' => Pages\ListSaccoProductAttributes::route('/'),
            'create' => Pages\CreateSaccoProductAttribute::route('/create'),
            'edit' => Pages\EditSaccoProductAttribute::route('/{record}/edit'),
        ];
    }
}

