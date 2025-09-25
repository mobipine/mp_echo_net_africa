<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberEditRequestResource\Pages;
use App\Filament\Resources\MemberEditRequestResource\RelationManagers;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberEditRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;

class MemberEditRequestResource extends Resource
{
    protected static ?string $model = MemberEditRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                TextColumn::make('name')->label("Name"),
                TextColumn::make("group")->label("Group"),
                TextColumn::make("national_id"),
                TextColumn::make("gender"),
                TextColumn::make("year_of_birth"),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'approved' => 'success',
                        'rejected' => 'danger',
                    }),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('Approve')
                    ->button()
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Member Edit Request')
                    ->modalDescription('Are you sure you want to approve this request? This will update the member\'s details permanently.')
                    ->modalSubmitActionLabel('Yes, approve it')
                    ->action(function (MemberEditRequest $record) {
                        // Find the member and apply the update from the request
                        $member = Member::where('phone', $record->phone_number)->first();
                        $group=Group::where('name',$record->group)->first();
                        $group_id=$group->id;
                        
                        if ($member) {
                            $dob = \Carbon\Carbon::parse($member->dob);

                            $dob->year = (int)$record->year_of_birth ;
                            Log::info($dob);
                            $member->update([
                                'name' => $record->name ?? $member->name,
                                'group_id' => $group_id ?? $member->group_id,
                                'national_id' => $record->national_id ?? $member->national_id,
                                'gender' => $record->gender ?? $member->gender,
                                'dob' => $dob ?? $member->dob,
                            ]);
                           
                            $record->update(['status' => 'approved']);
                            
                            return Notification::make()
                                ->title('Request Approved')
                                ->success()
                                ->send();
                            
                        }
                    })
                    ->visible(fn (MemberEditRequest $record) => $record->status === 'pending'),

                Tables\Actions\Action::make('Reject')
                    ->button()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject Member Edit Request')
                    ->modalDescription('Are you sure you want to reject this request? The existing member\'s details will be maintained.')
                    ->modalSubmitActionLabel('Yes, reject it')
                    ->action(function (MemberEditRequest $record) {
                        $record->update(['status' => 'rejected']);
                    })
                    ->visible(fn (MemberEditRequest $record) => $record->status === 'pending'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                
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
            'index' => Pages\ListMemberEditRequests::route('/'),
           
        ];
    }
}
