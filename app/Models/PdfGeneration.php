<?php

namespace App\Models;

use Database\Factories\PdfGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'user_id',
    'project_id',
    'type',
    'status',
    'progress',
    'attempt_count',
    'message',
    'parameters',
    'result',
    'error_message',
    'started_at',
    'completed_at',
    'expires_at',
])]
class PdfGeneration extends Model
{
    /** @use HasFactory<PdfGenerationFactory> */
    use HasFactory;

    public const TypeSchedule = 'schedule';

    public const TypeQuote = 'quote';

    public const TypePreparedQuote = 'prepared_quote';

    public const TypeDatasheets = 'datasheets';

    public const TypeDocumentPack = 'document_pack';

    public const StatusQueued = 'queued';

    public const StatusProcessing = 'processing';

    public const StatusCompleted = 'completed';

    public const StatusFailed = 'failed';

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
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
