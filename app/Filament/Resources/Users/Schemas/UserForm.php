<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->helperText('super_admin or admin grants access to this admin panel.'),

                // Inviting is the default way to add someone: they receive an
                // email and choose their own password.
                Toggle::make('send_invitation')
                    ->label('Send an invitation email')
                    ->helperText('The person sets their own password through the emailed link. Turn this off to set a password yourself.')
                    ->default(true)
                    ->dehydrated(false)
                    ->live()
                    ->visible(fn (string $operation): bool => $operation === 'create'),

                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation, callable $get): bool => $operation === 'create' && ! $get('send_invitation'))
                    ->visible(fn (string $operation, callable $get): bool => $operation !== 'create' || ! $get('send_invitation'))
                    ->helperText(fn (string $operation): string => $operation === 'create'
                        ? 'The password this user will sign in with.'
                        : 'Leave blank to keep the current password.')
                    ->maxLength(255),
            ]);
    }
}
