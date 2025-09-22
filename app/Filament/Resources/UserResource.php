<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    //add navigation group
    protected static ?string $navigationGroup = 'User Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\TextInput::make('name')->required(),

                \Filament\Forms\Components\TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),
                
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn($state) => !empty($state) ? bcrypt($state) : null)
                    ->dehydrated(fn($state) => filled($state))
                    ->required(fn($context) => $context === 'create'),
                
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Select a member to link this user account to. Leave empty for non-member users.'),

                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->required()
                    ->searchable(),

                FileUpload::make('profile_picture')
                    ->image()
                    ->imageEditor()
                    ->imageEditorAspectRatios([
                        '16:9',
                        '4:3',
                        '1:1',
                    ])
                    ->directory('profile-pictures')
                    ->nullable()
                    ->visibility('public')
                    ->enableDownload()
                    ->enableOpen(),

                
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('email')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('member.name')
                    ->label('Member')
                    ->sortable()
                    ->searchable()
                    ->placeholder('Not a member'),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                ImageColumn::make('profile_picture')
                    ->label('Profile Picture')
                    ->circular()
                    ->size(40)
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
