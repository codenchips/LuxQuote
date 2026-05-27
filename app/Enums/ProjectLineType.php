<?php

namespace App\Enums;

enum ProjectLineType: string
{
    case Standard = 'standard';
    case Modified = 'modified';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            ProjectLineType::Standard => 'Standard',
            ProjectLineType::Modified => 'Modified',
            ProjectLineType::Custom => 'Custom',
        };
    }
}
