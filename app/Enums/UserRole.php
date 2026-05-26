<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Users = 'users';

    public function label(): string
    {
        return match ($this) {
            UserRole::Admin => 'Admin',
            UserRole::Users => 'User',
        };
    }
}
