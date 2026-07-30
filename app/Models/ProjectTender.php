<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'salesforce_account_id',
    'account_name',
    'billing_city',
    'cef_region',
    'is_primary',
    'salesforce_tender_id',
    'account_payload',
    'created_by_id',
])]
class ProjectTender extends Model
{
    protected function casts(): array
    {
        return [
            'account_payload' => 'array',
            'is_primary' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
