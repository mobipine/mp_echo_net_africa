<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SMSResource\Pages;
use App\Filament\Resources\SMSResource\RelationManagers;
use App\Models\Group;
use App\Models\SMS;
use App\Models\SMSInbox;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SMSResource extends Resource
{
    protected static ?string $model = SMSInbox::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Messaging';




    public static function canCreate(): bool
    {
        return false; // Disable the create action
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //

                //do a form that will be used for the view action of the table
                Forms\Components\Grid::make(1)->schema([
                    Forms\Components\TextInput::make('id')
                        ->label('ID')
                        ->disabled()
                        ->required(),
                    Forms\Components\Textarea::make('message')
                        ->label('Message')
                        ->disabled()
                        ->required()
                        ->maxLength(500),
                    Forms\Components\TextInput::make('group_ids')
                        ->label('Groups')
                        ->disabled()
                        ->formatStateUsing(function ($state) {
                            $groups_array =  $state;
                            $group_names = Group::whereIn('id', $groups_array)->pluck('name')->toArray();
                            return is_array($group_names) ? implode(', ', $group_names) : $group_names;
                        }),
                    Forms\Components\TextInput::make('status')
                        ->label('Status')
                        ->disabled()
                        ->formatStateUsing(fn ($state) => ucfirst($state)), // Capitalize the status
                   
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('message')->label('Message')->limit(50)->searchable(),
            TextColumn::make('group_ids')
                ->label('Groups')
                ->formatStateUsing(function ($state) {
                    // dd($state);
                    $groups_array = explode(',', $state);
                    //get the names of the groups
                    $group_names = Group::whereIn('id', $groups_array)->pluck('name')->toArray();
                    // Return the names as a comma-separated string
                    return is_array($group_names) ? implode(', ', $group_names) : $group_names;
                    
                    // return is_array($state) ? implode(', ', $state) : $state;
                }),
            BadgeColumn::make('status')
                ->label('Status')
                ->colors([
                    'warning' => fn ($state): bool => $state === 'pending',
                    'success' => fn ($state): bool => $state === 'sent',
                    'danger' => fn ($state): bool => $state === 'failed',
                ])
                ->formatStateUsing(fn ($state) => ucfirst($state)), // Capitalize the status
            TextColumn::make('created_at')->label('Created At')->dateTime(),
        ])
        ->filters([
            //
        ])
        ->actions([
            Tables\Actions\ViewAction::make(),
        ])
        ->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    //change label of the resource
    public static function getLabel(): string
    {
        return 'Sent Messages';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSMS::route('/'),
            // 'create' => Pages\CreateSMS::route('/create'),
            // 'edit' => Pages\EditSMS::route('/{record}/edit'),
        ];
    }
}
