<?php

namespace App\Filament\Support;

use Filament\Support\Colors\Color;
use Illuminate\Support\Str;

class BadgeStyle
{
    /** @return string|array<int, string> */
    public static function filamentColor(mixed $state): string|array
    {
        $label = self::normalise($state);

        if ($brandColor = self::brandColor($label)) {
            return Color::hex($brandColor);
        }

        return match ($label) {
            'admin' => Color::hex('#B91C1C'),
            'approved', 'open' => Color::hex('#059669'),
            'approval requested', 'quoted' => Color::hex('#D97706'),
            'details' => Color::hex('#0B86C8'),
            'design' => Color::hex('#7C3AED'),
            'quotation' => Color::hex('#D97706'),
            'sales' => Color::hex('#2563EB'),
            'technical' => Color::hex('#0891B2'),
            'user' => Color::hex('#64748B'),
            'in progress' => Color::hex('#0284C7'),
            'draft' => 'gray',
            default => Color::hex(self::fallbackColor($label)),
        };
    }

    public static function classes(mixed $state): string
    {
        $label = self::normalise($state);

        if ($brandClass = self::brandClass($label)) {
            return $brandClass;
        }

        return match ($label) {
            'admin' => 'lux-badge-danger',
            'approved', 'open' => 'lux-badge-success',
            'approval requested', 'quotation', 'quoted' => 'lux-badge-warning',
            'details', 'in progress', 'sales' => 'lux-badge-info',
            'design' => 'lux-badge-purple',
            'technical' => 'lux-badge-cyan',
            'user', 'draft' => 'lux-badge-gray',
            default => 'lux-badge-'.self::fallbackBucket($label),
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

    private static function brandColor(string $label): ?string
    {
        if (Str::contains($label, 'tamlite')) {
            return '#0B86C8';
        }

        if (Str::contains($label, 'xcite')) {
            return '#C8102E';
        }

        return null;
    }

    private static function brandClass(string $label): ?string
    {
        if (Str::contains($label, 'tamlite')) {
            return 'lux-badge-tamlite';
        }

        if (Str::contains($label, 'xcite')) {
            return 'lux-badge-xcite';
        }

        return null;
    }

    private static function fallbackColor(string $label): string
    {
        return self::hslToHex(
            hue: crc32($label) % 360,
            saturation: 68,
            lightness: 44,
        );
    }

    private static function fallbackBucket(string $label): string
    {
        $buckets = ['blue', 'cyan', 'emerald', 'purple', 'rose', 'slate', 'warning'];

        return $buckets[crc32($label) % count($buckets)];
    }

    private static function hslToHex(int $hue, int $saturation, int $lightness): string
    {
        $saturation /= 100;
        $lightness /= 100;
        $chroma = (1 - abs((2 * $lightness) - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $match = $lightness - ($chroma / 2);

        [$red, $green, $blue] = match (true) {
            $hue < 60 => [$chroma, $x, 0],
            $hue < 120 => [$x, $chroma, 0],
            $hue < 180 => [0, $chroma, $x],
            $hue < 240 => [0, $x, $chroma],
            $hue < 300 => [$x, 0, $chroma],
            default => [$chroma, 0, $x],
        };

        return sprintf(
            '#%02X%02X%02X',
            (int) round(($red + $match) * 255),
            (int) round(($green + $match) * 255),
            (int) round(($blue + $match) * 255),
        );
    }
}
