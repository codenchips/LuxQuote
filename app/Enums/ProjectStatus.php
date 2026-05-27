<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Complete = 'complete';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            ProjectStatus::Draft => 'Draft',
            ProjectStatus::InProgress => 'In Progress',
            ProjectStatus::Complete => 'Complete',
            ProjectStatus::Cancelled => 'Cancelled',
            ProjectStatus::Archived => 'Archived',
        };
    }
}
