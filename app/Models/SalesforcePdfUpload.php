<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'project_revision_id',
    'document_type',
    'fingerprint_hash',
    'filename',
    'salesforce_content_version_id',
    'salesforce_content_document_id',
    'salesforce_url',
    'uploaded_at',
])]
class SalesforcePdfUpload extends Model
{
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ProjectRevision::class, 'project_revision_id');
    }
}
