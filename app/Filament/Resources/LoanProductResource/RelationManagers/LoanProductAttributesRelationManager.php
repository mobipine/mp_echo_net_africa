<?php

namespace App\Filament\Resources\LoanProductResource\RelationManagers;

use App\Models\LoanAttribute;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LoanProductAttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'LoanProductAttributes';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('loan_attribute_id')
                    ->label('Attribute')
                    ->native(false)
                    ->options(LoanAttribute::all()->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->reactive(),
                Forms\Components\Select::make('value')
                    ->label('Value')
                    ->native(false)
                    ->options([true => 'True', false => 'False'])
                    ->visible(fn($get) => optional(LoanAttribute::find($get('loan_attribute_id')))->type === 'boolean'),

                Forms\Components\Select::make('value')
                    ->label('Document Type')
                    ->multiple()
                    ->native(false)
                    ->options(\App\Models\DocsMeta::all()->pluck('name', 'id'))
                    ->visible(fn ($get) => optional(LoanAttribute::find($get('loan_attribute_id')))->type === 'file')
                    ->dehydrateStateUsing(fn ($state) => is_array($state) ? implode(',', $state) : $state)
                    ->afterStateUpdated(fn ($state, $set) => $set('value', implode(',', (array) $state))),

        
                Forms\Components\Select::make('value')
                    ->label('Value')
                    ->native(false)
                    ->options(function ($get) {
                        $attribute = LoanAttribute::find($get('loan_attribute_id'));
                        if ($attribute && in_array($attribute->type, ['select', 'multiselect'])) {
                            return collect(explode(',', $attribute->options))->mapWithKeys(fn($v) => [trim($v) => trim($v)]);
                        }
                        return [];
                    })
                    ->visible(fn($get) => in_array(optional(LoanAttribute::find($get('loan_attribute_id')))->type, ['select', 'multiselect'])),
                Forms\Components\TextInput::make('value')
                    ->label('Value')
                    ->maxLength(255)
                    ->visible(fn($get) => !in_array(optional(LoanAttribute::find($get('loan_attribute_id')))->type, ['boolean', 'file', 'select', 'multiselect'])),


                Forms\Components\TextInput::make('order')
                    ->numeric()
                    ->default(function ($get) {
                        $loanProductId = $this->getOwnerRecord()->id;
                        $attributeId = $get('loan_attribute_id');
                        $query =
                            \App\Models\LoanProductAttribute::where('loan_product_id', $loanProductId);
                        return $query->max('order') + 1;
                    })
                    ->hidden()
                    ->readOnly()
                    ->label('Order'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('order')
            ->columns([
                Tables\Columns\TextColumn::make('loanAttribute.name')->label('Attribute')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('loanAttribute.type')->label('Type')->sortable(),
                Tables\Columns\TextColumn::make('value')->label('Value')->sortable(),
                // Tables\Columns\TextColumn::make('order')->sortable(),
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
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
