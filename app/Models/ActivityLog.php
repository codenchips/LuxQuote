<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'project_id',
    'action_type',
    'user_email_snapshot',
    'project_name_snapshot',
    'revision_number',
    'payload',
])]
class ActivityLog extends Model
{
    /** No updated_at — these records are append-only. */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'revision_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $activityLog): void {
            if ($activityLog->revision_number !== null || $activityLog->project_id === null) {
                return;
            }

            $activityLog->revision_number = $activityLog->project?->revision;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
