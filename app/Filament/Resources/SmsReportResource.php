<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SmsReportResource\Pages;
use App\Filament\Resources\SmsReportResource\RelationManagers;
use App\Filament\Resources\SmsReportResource\Widgets\SentSmsStatsOverview;
use App\Models\SMSInbox;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SmsReportResource extends Resource
{
    protected static ?string $model = SMSInbox::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';
    protected static ?string $navigationLabel = 'SMS Reports';
    protected static ?string $navigationGroup = 'Analytics';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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

    
    public static function getWidgets(): array
{
    return [
        SentSmsStatsOverview::class,
    ];
}
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmsReports::route('/'),
            'create' => Pages\CreateSmsReport::route('/create'),
            'view' => Pages\ViewSmsReport::route('/{record}'),
            'edit' => Pages\EditSmsReport::route('/{record}/edit'),
        ];
    }
}
