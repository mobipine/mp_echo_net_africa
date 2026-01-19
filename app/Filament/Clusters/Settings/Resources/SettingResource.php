<?php

namespace App\Filament\Clusters\Settings\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Clusters\Settings\Pages\LoanNotificationSettings;
use App\Filament\Clusters\Settings\Resources\SettingResource\Pages;
use App\Filament\Clusters\Settings\Resources\SettingResource\RelationManagers;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $cluster = Settings::class;

    protected static ?string $navigationLabel = 'System Settings';

    protected static ?string $modelLabel = 'Setting';

    protected static ?string $pluralModelLabel = 'Settings';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Setting Details')
                    ->schema([
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique identifier for this setting'),

                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->helperText('Human-readable name for this setting'),

                        Forms\Components\Select::make('group')
                            ->options([
                                'general' => 'General',
                                'loan_notifications' => 'Loan Notifications',
                                'email' => 'Email',
                                'sms' => 'SMS',
                                'system' => 'System',
                                'security' => 'Security',
                                'api' => 'API',
                            ])
                            ->required()
                            ->default('general'),

                        Forms\Components\Select::make('type')
                            ->options([
                                'string' => 'Text',
                                'boolean' => 'Yes/No',
                                'integer' => 'Number',
                                'json' => 'JSON',
                            ])
                            ->required()
                            ->default('string')
                            ->reactive(),

                        Forms\Components\TextInput::make('value')
                            ->required()
                            ->visible(fn ($get) => in_array($get('type'), ['string', 'integer']))
                            ->helperText('The setting value'),

                        Forms\Components\Toggle::make('value')
                            ->visible(fn ($get) => $get('type') === 'boolean')
                            ->helperText('Enable or disable this setting'),

                        Forms\Components\Textarea::make('value')
                            ->visible(fn ($get) => $get('type') === 'json')
                            ->helperText('JSON formatted value'),

                        Forms\Components\Textarea::make('description')
                            ->helperText('Optional description of what this setting does'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'loan_notifications' => 'success',
                        'email' => 'info',
                        'sms' => 'warning',
                        'system' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->size('sm'),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('value')
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 50 ? $state : null;
                    }),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->options([
                        'general' => 'General',
                        'loan_notifications' => 'Loan Notifications',
                        'email' => 'Email',
                        'sms' => 'SMS',
                        'system' => 'System',
                        'security' => 'Security',
                        'api' => 'API',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'string' => 'Text',
                        'boolean' => 'Yes/No',
                        'integer' => 'Number',
                        'json' => 'JSON',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('loan_notifications')
                    ->label('Loan Notification Settings')
                    ->icon('heroicon-o-envelope')
                    ->color('primary')
                    ->url(fn (): string => LoanNotificationSettings::getUrl())
                    ->visible(fn (): bool => request()->get('tableFilters.group.value') === 'loan_notifications'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('group')
            ->groups([
                Tables\Grouping\Group::make('group')
                    ->label('Group')
                    ->collapsible(),
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
            'index' => Pages\ListSettings::route('/'),
            'create' => Pages\CreateSetting::route('/create'),
            'edit' => Pages\EditSetting::route('/{record}/edit'),
        ];
    }
}
