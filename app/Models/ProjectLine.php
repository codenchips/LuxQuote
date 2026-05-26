<?php

namespace App\Models;

use App\Enums\ProjectLineType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_area_id', 'code', 'description', 'qty', 'type', 'unit_price', 'notes', 'status', 'sort_order'])]
class ProjectLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ProjectLineType::class,
            'qty' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ProjectArea::class, 'project_area_id');
    }
}
