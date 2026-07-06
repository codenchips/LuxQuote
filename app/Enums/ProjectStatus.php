<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case ApprovalRequested = 'approval_requested';
    case Approved = 'approved';
    case Quoted = 'quoted';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            ProjectStatus::Draft => 'Draft',
            ProjectStatus::InProgress => 'In Progress',
            ProjectStatus::ApprovalRequested => 'Approval Requested',
            ProjectStatus::Approved => 'Approved',
            ProjectStatus::Quoted => 'Quoted',
            ProjectStatus::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            ProjectStatus::Draft, ProjectStatus::Archived => 'gray',
            ProjectStatus::InProgress => 'info',
            ProjectStatus::ApprovalRequested => 'warning',
            ProjectStatus::Approved => 'success',
            ProjectStatus::Quoted => 'warning',
        };
    }
}
