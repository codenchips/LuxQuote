<?php

namespace App\Models;

use Database\Factories\ReportingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'activity_log_id',
    'event_type',
    'generation_batch_key',
    'occurred_at',
    'user_id',
    'user_name_snapshot',
    'user_email_snapshot',
    'project_id',
    'project_reference_snapshot',
    'project_name_snapshot',
    'owner_name_snapshot',
    'owner_email_snapshot',
    'revision_number',
    'currency',
    'net_value',
    'gross_value',
    'has_cover',
    'effective_cover_percentage',
    'include_datasheets',
    'include_cover_letter',
    'include_legal_page',
    'tender_count',
    'document_count',
    'metadata',
])]
class ReportingEvent extends Model
{
    /** @use HasFactory<ReportingEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'net_value' => 'decimal:2',
            'gross_value' => 'decimal:2',
            'has_cover' => 'boolean',
            'effective_cover_percentage' => 'decimal:3',
            'include_datasheets' => 'boolean',
            'include_cover_letter' => 'boolean',
            'include_legal_page' => 'boolean',
            'metadata' => 'array',
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

    public function products(): HasMany
    {
        return $this->hasMany(ReportingEventProduct::class);
    }
}
