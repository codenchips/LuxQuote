<?php

namespace App\Services;

use Illuminate\Support\Str;

class ProjectNameFormatter
{
    public function fromSalesforce(?string $name): string
    {
        return (string) preg_replace_callback(
            '/\b[\pL\pN][\pL\pN\']*\b/u',
            fn (array $matches): string => $this->formatWord($matches[0]),
            (string) $name,
        );
    }

    private function formatWord(string $word): string
    {
        if (preg_match('/^\p{Lu}{2,}$/u', $word) === 1 && mb_strlen($word) <= 3) {
            return $word;
        }

        return Str::of($word)
            ->lower()
            ->title()
            ->toString();
    }
}
