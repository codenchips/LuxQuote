<?php

namespace App\Filament\Support;

class BadgeStyle
{
    public static function filamentColor(mixed $state): string
    {
        $label = self::normalise($state);

        return match ($label) {
            'admin' => 'danger',
            'approved', 'open' => 'success',
            'approval requested', 'details', 'design', 'quotation', 'quoted', 'sales', 'technical', 'user', 'xcite' => 'warning',
            'in progress' => 'info',
            default => 'gray',
        };
    }

    public static function classes(mixed $state): string
    {
        return match (self::filamentColor($state)) {
            'danger' => 'lux-badge-danger',
            'success' => 'lux-badge-success',
            'warning' => 'lux-badge-warning',
            'info' => 'lux-badge-info',
            'primary' => 'lux-badge-primary',
            default => 'lux-badge-gray',
        };
    }

    private static function normalise(mixed $state): string
    {
        return str((string) $state)
            ->replace(['_', '-'], ' ')
            ->squish()
            ->lower()
            ->toString();
    }
}
