<?php

namespace App\Filament\Resources\SaccoProductResource\RelationManagers;

use App\Models\SaccoProductAttribute;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProductAttributeValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'attributeValues';

    protected static ?string $title = 'Product Attributes';
    
    protected static ?string $description = 'Configure attribute values for this product';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('attribute_id')
                    ->label('Attribute')
                    ->options(function () {
                        // Get the product
                        $product = $this->getOwnerRecord();
                        
                        // Get already assigned attributes
                        $assignedIds = $product->attributeValues()->pluck('attribute_id')->toArray();
                        
                        // Get available attributes (not yet assigned)
                        return SaccoProductAttribute::whereNotIn('id', $assignedIds)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(function ($attr) {
                                return [$attr->id => "{$attr->name} ({$attr->type})"];
                            });
                    })
                    ->required()
                    ->searchable()
                    ->reactive()
                    ->helperText('Select the attribute to configure'),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\TextInput::make('value')
                            ->label('Value')
                            ->required()
                            ->visible(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return true;
                                $attr = SaccoProductAttribute::find($attrId);
                                return $attr && in_array($attr->type, ['string', 'integer', 'decimal']);
                            })
                            ->numeric(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return false;
                                $attr = SaccoProductAttribute::find($attrId);
                                return $attr && in_array($attr->type, ['integer', 'decimal']);
                            })
                            ->helperText(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return '';
                                $attr = SaccoProductAttribute::find($attrId);
                                if ($attr && $attr->default_value) {
                                    return "Default: {$attr->default_value}";
                                }
                                return '';
                            }),
                        
                        Forms\Components\Toggle::make('value')
                            ->label('Value')
                            ->visible(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return false;
                                $attr = SaccoProductAttribute::find($attrId);
                                return $attr && $attr->type === 'boolean';
                            })
                            ->default(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return false;
                                $attr = SaccoProductAttribute::find($attrId);
                                return $attr && $attr->default_value === 'true';
                            }),
                        
                        Forms\Components\DatePicker::make('value')
                            ->label('Value')
                            ->visible(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return false;
                                $attr = SaccoProductAttribute::find($attrId);
                                return $attr && $attr->type === 'date';
                            }),
                        
                        Forms\Components\Select::make('value')
                            ->label('Value')
                            ->options(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return [];
                                $attr = SaccoProductAttribute::find($attrId);
                                if (!$attr || $attr->type !== 'select') return [];
                                
                                $options = $attr->options;
                                if (is_string($options)) {
                                    $options = json_decode($options, true) ?: [];
                                }
                                
                                return is_array($options) ? array_combine($options, $options) : [];
                            })
                            ->visible(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return false;
                                $attr = SaccoProductAttribute::find($attrId);
                                return $attr && $attr->type === 'select';
                            }),
                        
                        Forms\Components\Textarea::make('value')
                            ->label('Value (JSON)')
                            ->rows(4)
                            ->visible(function (callable $get) {
                                $attrId = $get('attribute_id');
                                if (!$attrId) return false;
                                $attr = SaccoProductAttribute::find($attrId);
                                return $attr && $attr->type === 'json';
                            })
                            ->helperText('Enter valid JSON format'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('attribute.name')
                    ->label('Attribute')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Bold),
                
                Tables\Columns\TextColumn::make('attribute.slug')
                    ->label('Slug')
                    ->badge()
                    ->color('info')
                    ->searchable(),
                
                Tables\Columns\BadgeColumn::make('attribute.type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'string',
                        'success' => 'integer',
                        'warning' => 'decimal',
                        'info' => 'boolean',
                        'danger' => 'select',
                        'secondary' => 'json',
                    ]),
                
                Tables\Columns\TextColumn::make('value')
                    ->label('Value')
                    ->formatStateUsing(function ($state, $record) {
                        $attr = $record->attribute;
                        if (!$attr) return $state;
                        
                        if ($attr->type === 'boolean') {
                            return $state === 'true' || $state === '1' || $state === true ? '✅ Yes' : '❌ No';
                        }
                        
                        if ($attr->type === 'decimal') {
                            return is_numeric($state) ? number_format((float)$state, 2) : $state;
                        }
                        
                        if ($attr->type === 'json') {
                            $decoded = is_string($state) ? json_decode($state, true) : $state;
                            return is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT) : $state;
                        }
                        
                        return $state;
                    })
                    ->limit(50)
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    ->color('success'),
                
                Tables\Columns\IconColumn::make('attribute.is_required')
                    ->label('Required')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Attribute'),
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
            ->emptyStateHeading('No attributes configured')
            ->emptyStateDescription('Add attributes to configure this product\'s behavior')
            ->emptyStateIcon('heroicon-o-tag');
    }
}

