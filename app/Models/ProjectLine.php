<?php

namespace App\Models;

use App\Enums\ProjectLineType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_area_id', 'product_id', 'code', 'ref', 'description', 'qty', 'type', 'unit_price', 'cover_1', 'cover_2', 'cover_3', 'notes', 'status', 'approved', 'approved_at', 'approved_by', 'validation_flagged', 'validation_note', 'sort_order'])]
class ProjectLine extends Model
{
    use HasFactory;

    protected $attributes = [
        'approved' => false,
        'validation_flagged' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => ProjectLineType::class,
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'cover_1' => 'decimal:2',
            'cover_2' => 'decimal:2',
            'cover_3' => 'decimal:2',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
            'validation_flagged' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ProjectArea::class, 'project_area_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
