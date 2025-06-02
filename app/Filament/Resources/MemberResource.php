<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Models\Member;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Select::make('group_id')
                ->native(false)
                    ->label('Group')
                    ->relationship('group', 'name')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('name')->required()->placeholder('e.g. John Doe'),
                \Filament\Forms\Components\TextInput::make('email')->email()->placeholder('john.doe@example.com')->required()->maxLength(255)->unique(ignoreRecord: true),
                \Filament\Forms\Components\TextInput::make('phone')->placeholder('e.g. 0712345678'),
                \Filament\Forms\Components\TextInput::make('national_id')->required()->placeholder('e.g. 11111111')
                    ->unique(ignoreRecord: true)
                    ->maxLength(8)
                    ->minLength(7)
                    ->numeric(),
                \Filament\Forms\Components\Select::make('gender')
                ->native(false)
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                    ]),
                \Filament\Forms\Components\DatePicker::make('dob')->label('Date of Birth'),
                \Filament\Forms\Components\Select::make('marital_status')
                ->native(false)
                    ->options([
                        'single' => 'Single',
                        'married' => 'Married',
                    ]),
                \Filament\Forms\Components\FileUpload::make('profile_picture')
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
                    ->enableOpen()

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // \Filament\Tables\Columns\ImageColumn::make('profile_picture')->label('pfp')->circular()
                //     ->disk('public')
                //     ->size(40)
                //     ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('group.name')->label('Group')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('email')->sortable()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('phone')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('national_id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('gender')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('dob')->date()->sortable(),
                \Filament\Tables\Columns\TextColumn::make('marital_status')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                // Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->color('gray')->icon('heroicon-o-eye')->label('View'),
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
            RelationManagers\DependantsRelationManager::class,
            RelationManagers\KycDocumentRelationManager::class,
            RelationManagers\EmailInboxesRelationManager::class,
            RelationManagers\SmsInboxesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/view'),
            // 'edit' => Pages\EditMemberTabbed::route('/{record}/edit'),

        ];
    }
}
