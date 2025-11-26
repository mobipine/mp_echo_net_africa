<?php

namespace App\Filament\Clusters\Settings\Resources;

use App\Filament\Clusters\Settings;
use App\Filament\Clusters\Settings\Resources\DocsMetaResource\Pages;
use App\Filament\Clusters\Settings\Resources\DocsMetaResource\RelationManagers;
use App\Models\DocsMeta;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DocsMetaResource extends Resource
{
    protected static ?string $model = DocsMeta::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $cluster = Settings::class;


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                //make a multi-select for tags
                Forms\Components\Select::make('tags')
                    ->label('Tags')
                    ->options([
                        'group_kyc' => "Group KYC",
                        'member_kyc' => 'Member KYC',
                        'loan_application' => 'Loan Application',
                        'collaterals' => 'Collaterals',
                    ])
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required()
                    ->helperText('Select one or more tags for this document type'),
                Forms\Components\Select::make('expiry')
                    ->options([
                        'yes' => 'Yes',
                        'no' => 'No',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->maxLength(255),
                Forms\Components\TextInput::make('max_file_count')
                    ->required()
                    ->maxLength(255),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tags')
                    ->label('Tags')
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return new \Illuminate\Support\HtmlString('<span class="text-gray-400">—</span>');
                        }
                        $tagLabels = [
                            'group_kyc' => "Group KYC",
                            'member_kyc' => 'Member KYC',
                            'loan_application' => 'Loan Application',
                            'collaterals' => 'Collaterals',
                        ];

                        $tags = is_array($state) ? $state : [$state];
                        $badges = collect($tags)->map(function ($tag) use ($tagLabels) {
                            $label = $tagLabels[$tag] ?? $tag;
                            return '<span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">' . htmlspecialchars($label) . '</span>';
                        })->join(' ');

                        return new \Illuminate\Support\HtmlString($badges);
                    })
                    ->searchable(),

                //display the tags as badges

                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('max_file_count')
                    ->label('Max Files')
                    ->sortable(),
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
            'index' => Pages\ListDocsMetas::route('/'),
            'create' => Pages\CreateDocsMeta::route('/create'),
            'edit' => Pages\EditDocsMeta::route('/{record}/edit'),
        ];
    }

    //change title of the resource
    public static function getModelLabel(): string
    {
        return 'Document Management';
    }

    //change label of the resource
    public static function getPluralModelLabel(): string
    {
        return 'Document Management';
    }


}
