<?php

namespace App\Enums;

enum ProjectRevisionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            ProjectRevisionStatus::Draft => 'Draft',
            ProjectRevisionStatus::Approved => 'Approved',
        };
    }
}
