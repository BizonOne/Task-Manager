<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\Invitations;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(','),
                TextColumn::make('account_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (User $record): string => match ($record->account_status) {
                        'invited' => 'Invitation pending',
                        'no_password' => 'No password',
                        default => 'Active',
                    })
                    ->color(fn (User $record): string => match ($record->account_status) {
                        'invited' => 'warning',
                        'no_password' => 'danger',
                        default => 'success',
                    }),
                TextColumn::make('invitation_accepted_at')
                    ->label('Invite accepted')
                    ->dateTime()
                    ->since()
                    ->tooltip(fn (User $record): ?string => $record->invitation_accepted_at?->toDayDateTimeString())
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('invitedBy.name')
                    ->label('Invited by')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('invited_at')
                    ->label('Invited')
                    ->dateTime()
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime()
                    ->tooltip(fn (User $record): ?string => $record->created_at?->toDayDateTimeString())
                    ->sortable(),
                TextColumn::make('last_active_at')
                    ->label('Last active')
                    ->dateTime()
                    ->since()
                    ->tooltip(fn (User $record): ?string => $record->last_active_at?->toDayDateTimeString())
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->label('Role'),
                Filter::make('invitation_pending')
                    ->label('Invitation pending')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('invitation_token')
                        ->whereNull('invitation_accepted_at')),
                Filter::make('never_active')
                    ->label('Never active')
                    ->query(fn (Builder $query): Builder => $query->whereNull('last_active_at')),
            ])
            ->recordActions([
                Action::make('resendInvitation')
                    ->label('Resend invite')
                    ->icon('heroicon-o-envelope')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record): string => "A fresh invitation link will be emailed to {$record->email}. Any previous link stops working.")
                    ->visible(fn (User $record): bool => $record->invitation_accepted_at === null)
                    ->action(function (User $record): void {
                        Invitations::send($record, Auth::user());

                        Notification::make()
                            ->title('Invitation sent')
                            ->body("We emailed a new invitation to {$record->email}.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
