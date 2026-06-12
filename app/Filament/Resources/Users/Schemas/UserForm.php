<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\PermissionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Details')
                    ->description('Basic account information and role assignment.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->unique(ignoreRecord: true)
                            ->required()
                            ->maxLength(255),
                        Select::make('permission_group_id')
                            ->label('Group')
                            ->relationship('permissionGroup', 'name')
                            ->preload()
                            ->searchable()
                            ->required()
                            ->default(fn (): ?int => PermissionGroup::where('slug', 'user')->value('id')),
                    ]),
                Section::make('Password')
                    ->description('Leave blank when editing to keep the current password.')
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->confirmed()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label('Confirm password')
                            ->password()
                            ->dehydrated(false)
                            ->required(fn (string $context): bool => $context === 'create'),
                    ]),
            ]);
    }
}
