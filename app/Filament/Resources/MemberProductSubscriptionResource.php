<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberProductSubscriptionResource\Pages;
use App\Models\MemberProductSubscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;

class MemberProductSubscriptionResource extends Resource
{
    protected static ?string $model = MemberProductSubscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    
    protected static ?string $navigationGroup = 'SACCO Management';
    
    protected static ?int $navigationSort = 6;
    
    protected static ?string $navigationLabel = 'Product Subscriptions';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'name')
                    ->required()
                    ->searchable(),
                
                Forms\Components\Select::make('sacco_product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->required()
                    ->searchable(),
                
                Forms\Components\DatePicker::make('subscription_date')
                    ->required()
                    ->default(now()),
                
                Forms\Components\DatePicker::make('start_date')
                    ->required()
                    ->default(now()),
                
                Forms\Components\DatePicker::make('end_date'),
                
                Forms\Components\Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'suspended' => 'Suspended',
                    ])
                    ->required()
                    ->default('active'),
                
                Forms\Components\TextInput::make('total_paid')
                    ->numeric()
                    ->default(0)
                    ->prefix('KES')
                    ->disabled(),
                
                Forms\Components\TextInput::make('total_expected')
                    ->numeric()
                    ->prefix('KES'),
                
                Forms\Components\TextInput::make('payment_count')
                    ->numeric()
                    ->default(0)
                    ->disabled(),
                
                Forms\Components\DatePicker::make('next_payment_date'),
                
                Forms\Components\Textarea::make('notes')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member.name')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->badge()
                    ->color('info'),
                
                Tables\Columns\TextColumn::make('total_paid')
                    ->label('Paid')
                    ->money('KES')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('total_expected')
                    ->label('Expected')
                    ->money('KES')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('outstanding_amount')
                    ->label('Outstanding')
                    ->money('KES')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color(fn ($record) => $record->outstanding_amount > 0 ? 'warning' : 'success'),
                
                Tables\Columns\TextColumn::make('payment_count')
                    ->label('Payments')
                    ->badge()
                    ->color('gray'),
                
                Tables\Columns\TextColumn::make('next_payment_date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'completed',
                        'info' => 'active',
                        'warning' => 'suspended',
                        'danger' => 'cancelled',
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'suspended' => 'Suspended',
                    ]),
                
                Tables\Filters\SelectFilter::make('sacco_product_id')
                    ->label('Product')
                    ->relationship('product', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
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
            'index' => Pages\ListMemberProductSubscriptions::route('/'),
            'view' => Pages\ViewMemberProductSubscription::route('/{record}'),
        ];
    }
}

