<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmailInboxesRelationManager extends RelationManager
{
    protected static string $relationship = 'emailInboxes';

    protected static ?string $title = 'Send Email';

    public function form(Form $form): Form
    {
        return $form
        ->schema([
            //show the member's name but submit the member_id
            Forms\Components\TextInput::make('member_id')
                ->default($this->getOwnerRecord()->id)
                ->hidden()                
                ->readOnly(),

            //email input pre-filled with the member's email and readonly
            Forms\Components\TextInput::make('email')
                ->default($this->getOwnerRecord()->email)
                ->readOnly(),


            Forms\Components\TextInput::make('subject')->required(),
            Forms\Components\Textarea::make('body')->required(),
            Forms\Components\Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'sent' => 'Sent',
                    'failed' => 'Failed',
                ])
                ->hidden()
                ->default('pending')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject'),
                Tables\Columns\TextColumn::make('body'),
                Tables\Columns\BadgeColumn::make('status')
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'sent' => 'success',
                        'failed' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
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
