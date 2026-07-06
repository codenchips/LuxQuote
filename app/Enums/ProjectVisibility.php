<?php

namespace App\Enums;

enum ProjectVisibility: string
{
    case Open = 'open';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            ProjectVisibility::Open => 'Open',
            ProjectVisibility::Private => 'Private',
        };
    }

    public function color(): string
    {
        return match ($this) {
            ProjectVisibility::Open => 'success',
            ProjectVisibility::Private => 'warning',
        };
    }
}
