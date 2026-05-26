<?php

namespace App\Enums;

enum ProjectLineType: string
{
    case Standard = 'standard';
    case Temp = 'temp';

    public function label(): string
    {
        return match ($this) {
            ProjectLineType::Standard => 'Standard',
            ProjectLineType::Temp => 'TEMP',
        };
    }
}
