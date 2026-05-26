<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

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
                    ->unique(ignoreRecord: true)
                    ->required()
                    ->maxLength(255),
                TextInput::make('password')
                    ->password()
                    ->confirmed()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->helperText(fn (string $context): ?string => $context === 'edit' ? 'Leave blank to keep the current password.' : null)
                    ->maxLength(255),
                TextInput::make('password_confirmation')
                    ->label('Confirm password')
                    ->password()
                    ->dehydrated(false)
                    ->required(fn (string $context): bool => $context === 'create'),
            ]);
    }
}
