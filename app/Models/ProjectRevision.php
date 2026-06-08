<?php

namespace App\Models;

use App\Enums\ProjectRevisionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'revision_number', 'created_by', 'validated', 'validated_at', 'validated_by', 'status'])]
class ProjectRevision extends Model
{
    protected $attributes = [
        'validated' => false,
        'status' => ProjectRevisionStatus::Draft->value,
    ];

    protected function casts(): array
    {
        return [
            'validated' => 'boolean',
            'validated_at' => 'datetime',
            'status' => ProjectRevisionStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(ProjectArea::class)->orderBy('sort_order');
    }
}
