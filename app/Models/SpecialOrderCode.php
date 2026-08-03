<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

#[Fillable(['code', 'description', 'price', 'qty', 'requires_approval', 'show_on_schedules', 'show_on_quotes'])]
class SpecialOrderCode extends Model
{
    /** @var Collection<int, self>|null */
    private static ?Collection $cachedCodes = null;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'qty' => 'integer',
            'requires_approval' => 'boolean',
            'show_on_schedules' => 'boolean',
            'show_on_quotes' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $specialOrderCode): void {
            $specialOrderCode->normalised_code = self::normaliseCode($specialOrderCode->code);
        });

        static::saved(fn (): null => self::clearCachedCodes());
        static::deleted(fn (): null => self::clearCachedCodes());
    }

    public static function normaliseCode(?string $code): string
    {
        return preg_replace('/\s+/', '', mb_strtoupper(trim((string) $code))) ?? '';
    }

    public static function findForCode(?string $code): ?self
    {
        $normalisedCode = self::normaliseCode($code);

        if ($normalisedCode === '') {
            return null;
        }

        return self::allCached()->first(
            fn (self $specialOrderCode): bool => $specialOrderCode->normalised_code === $normalisedCode,
        );
    }

    /**
     * @return Collection<int, self>
     */
    private static function allCached(): Collection
    {
        if (self::$cachedCodes !== null) {
            return self::$cachedCodes;
        }

        if (! Schema::hasTable('special_order_codes')) {
            return self::$cachedCodes = collect();
        }

        return self::$cachedCodes = self::query()
            ->orderBy('code')
            ->get();
    }

    private static function clearCachedCodes(): null
    {
        self::$cachedCodes = null;

        return null;
    }

    /**
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => mb_strtoupper(trim((string) $value)),
        );
    }
}
