<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'reference_number',
    'customer_name',
    'contractor',
    'site_location',
    'owner_email',
    'created_by_email',
    'department',
    'date',
    'revision',
    'visibility',
    'status',
    'branch_name',
    'cover_percentage',
    'quote_notes',
    'internal_notes',
    'general_notes',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'visibility' => ProjectVisibility::class,
            'status' => ProjectStatus::class,
            'date' => 'date',
            'cover_percentage' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(ProjectArea::class)->orderBy('sort_order');
    }

    protected static function booted(): void
    {
        static::created(function (Project $project): void {
            $project->areas()->create(['name' => 'Ground Floor', 'sort_order' => 0]);
        });
    }
}
