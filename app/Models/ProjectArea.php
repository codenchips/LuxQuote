<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'name', 'sort_order'])]
class ProjectArea extends Model
{
    use HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProjectLine::class)->orderBy('sort_order');
    }

    public function getLineTotalQtyAttribute(): int
    {
        return $this->lines->sum('qty');
    }

    public function getLineTotalAttribute(): float
    {
        return $this->lines->sum(
            fn (ProjectLine $line): float => ($line->qty ?? 0) * (float) ($line->unit_price ?? 0)
        );
    }
}
