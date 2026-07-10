<?php

namespace App\Enums;

enum ProjectVisibility: string
{
    case Open = 'open';
    case Private = 'private';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            ProjectVisibility::Open => 'Open',
            ProjectVisibility::Private => 'Private',
            ProjectVisibility::Team => 'Team',
        };
    }

    public function color(): string
    {
        return match ($this) {
            ProjectVisibility::Open => 'success',
            ProjectVisibility::Private => 'warning',
            ProjectVisibility::Team => 'info',
        };
    }
}
