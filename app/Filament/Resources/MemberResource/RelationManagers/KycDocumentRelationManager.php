<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\DocsMeta;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class KycDocumentRelationManager extends RelationManager
{
    protected static string $relationship = 'kycDocuments';
    protected static ?string $recordTitleAttribute = 'document_type';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            // Forms\Components\TextInput::make('document_type')->required(),
            //select for document type
            Forms\Components\Select::make('document_type')
                ->options(DocsMeta::all()->pluck('name', 'id'))
                ->required()
                ->native(false)
                ->searchable(),
            Forms\Components\FileUpload::make('file_path')->directory('kyc-documents')->required()->visibility('public'),
            Forms\Components\Textarea::make('description'),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            

            //show the name of the document type
            Tables\Columns\TextColumn::make('document_type')
                ->formatStateUsing(function ($state) {
                    return DocsMeta::where('id', $state)->first()->name;
                })
                ->sortable()
                ->searchable(),
            

            Tables\Columns\TextColumn::make('file_path')
                ->url(function ($record) {
                    // dd($record);
                    return Storage::disk('public')->url($record->file_path);
                })
                ->label('File Link')
                ->html()
                ->sortable(),
            Tables\Columns\TextColumn::make('description')->limit(30),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])->headerActions([
            Tables\Actions\CreateAction::make(),
        ]);
    }
} 