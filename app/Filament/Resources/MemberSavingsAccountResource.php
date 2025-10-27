<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberSavingsAccountResource\Pages;
use App\Models\MemberSavingsAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;

class MemberSavingsAccountResource extends Resource
{
    protected static ?string $model = MemberSavingsAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    
    protected static ?string $navigationGroup = 'SACCO Management';
    
    protected static ?int $navigationSort = 3;
    
    protected static ?string $navigationLabel = 'Savings Accounts';

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
                    ->label('Savings Product')
                    ->relationship('product', 'name')
                    ->required()
                    ->searchable(),
                
                Forms\Components\TextInput::make('account_number')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),
                
                Forms\Components\DatePicker::make('opening_date')
                    ->required()
                    ->default(now()),
                
                Forms\Components\Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'dormant' => 'Dormant',
                        'closed' => 'Closed',
                    ])
                    ->required()
                    ->default('active'),
                
                Forms\Components\DatePicker::make('closed_date')
                    ->visible(fn ($get) => $get('status') === 'closed'),
                
                Forms\Components\Textarea::make('notes')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_number')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->copyable(),
                
                Tables\Columns\TextColumn::make('member.name')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->badge()
                    ->color('info'),
                
                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->money('KES')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color('success'),
                
                Tables\Columns\TextColumn::make('opening_date')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'warning' => 'dormant',
                        'danger' => 'closed',
                    ]),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'dormant' => 'Dormant',
                        'closed' => 'Closed',
                    ]),
                
                Tables\Filters\SelectFilter::make('sacco_product_id')
                    ->label('Product')
                    ->relationship('product', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('view_transactions')
                    ->label('Transactions')
                    ->icon('heroicon-o-list-bullet')
                    ->url(fn (MemberSavingsAccount $record): string => 
                        route('filament.admin.resources.transactions.index', [
                            'tableFilters' => [
                                'savings_account_id' => ['value' => $record->id]
                            ]
                        ])
                    ),
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
            'index' => Pages\ListMemberSavingsAccounts::route('/'),
            'view' => Pages\ViewMemberSavingsAccount::route('/{record}'),
        ];
    }
}

