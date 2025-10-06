<?php

namespace App\Filament\Clusters\Settings\Resources;

use App\Enums\NotificationEvent;
use App\Filament\Clusters\Settings;
use App\Filament\Clusters\Settings\Resources\NotificationTemplateResource\Pages;
use App\Filament\Clusters\Settings\Resources\NotificationTemplateResource\RelationManagers;
use App\Models\NotificationTemplate;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $cluster = Settings::class;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // SLUG (Unique Identifier)
                Select::make('slug')
                ->label('System Event / Template Identifier')
                ->options(NotificationEvent::class)
                ->required()
                ->unique(ignoreRecord: true) // Ensure each slug is used only once
                ->helperText('Select the specific coded event that triggers this notification.'),

                // CHANNELS
                CheckboxList::make('channels')
                    ->options([
                        'email' => 'Email',
                        'sms' => 'SMS (Text Message)',
                        'whatsapp' => 'WhatsApp',
                    ])
                    ->columns(3)
                    ->required()
                    ->live(), // Important: Enables conditional visibility for content fields

                // STATUS
                Toggle::make('is_active')
                    ->label('Template Is Active')
                    ->default(true),

                // CONTENT FIELDS (Conditionally Visible)
                TextInput::make('subject')
                    ->label('Email/SMS Subject Line')
                    ->maxLength(255)
                    ->columnSpan('full'),

                // Email Body
                RichEditor::make('body_email')
                    ->label('Email Body (HTML Content)')
                    ->placeholder('Use {{placeholders}} for dynamic content')
                    ->columnSpan('full')
                    ->visible(fn ($get) => in_array('email', $get('channels') ?? [])),

                // SMS Body
                Textarea::make('body_sms')
                    ->label('SMS Body (Plain Text)')
                    ->placeholder('Use {{placeholders}} for dynamic content')
                    ->maxLength(160) // SMS limit
                    ->rows(4)
                    ->columnSpan('full')
                    ->visible(fn ($get) => in_array('sms', $get('channels') ?? [])),

                // WhatsApp Body
                Textarea::make('body_whatsapp')
                    ->label('WhatsApp Body (Plain Text)')
                    ->placeholder('Use {{placeholders}} for dynamic content')
                    ->rows(4)
                    ->columnSpan('full')
                    ->visible(fn ($get) => in_array('whatsapp', $get('channels') ?? [])),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('channels')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('body_sms')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('body_email')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('body_whatsapp')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('subject')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'create' => Pages\CreateNotificationTemplate::route('/create'),
            'edit' => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
