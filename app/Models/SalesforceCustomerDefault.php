<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['salesforce_account_id', 'cover_1', 'cover_2', 'cover_3', 'cover_direction'])]
class SalesforceCustomerDefault extends Model
{
    use HasFactory;

    /** @return array<string, string> */
    public static function coverValues(?string $accountId): array
    {
        $defaults = filled($accountId) ? static::where('salesforce_account_id', $accountId)->first() : null;

        return collect(['cover_1', 'cover_2', 'cover_3'])
            ->mapWithKeys(fn (string $field): array => [$field => $defaults?->{$field} ?? '5.00'])
            ->all() + ['cover_direction' => $defaults?->cover_direction ?? 'added'];
    }

    protected function casts(): array
    {
        return ['cover_1' => 'decimal:2', 'cover_2' => 'decimal:2', 'cover_3' => 'decimal:2'];
    }
}
