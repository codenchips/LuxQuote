<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'original_activity_log_id',
    'user_id',
    'project_id',
    'action_type',
    'user_email_snapshot',
    'project_name_snapshot',
    'revision_number',
    'payload',
    'created_at',
    'archived_at',
])]
class ActivityLogArchive extends Model
{
    /** No updated_at — archived logs are append-only snapshots. */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'revision_number' => 'integer',
            'created_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
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
