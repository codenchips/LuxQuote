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

    public function netUnitPriceForProject(Project $project): ?float
    {
        if ($this->unit_price === null) {
            return null;
        }

        if (! $project->has_cover) {
            return (float) $this->unit_price;
        }

        $multiplier = $this->coverMultiplierForProject($project);
        $unitPrice = (float) $this->unit_price;

        if ($project->cover_direction === 'added') {
            return $multiplier > 0 ? round($unitPrice / $multiplier, 2) : $unitPrice;
        }

        return round($unitPrice * $multiplier, 2);
    }

    public function netLineTotalForProject(Project $project): float
    {
        return ($this->qty ?? 0) * (float) ($this->netUnitPriceForProject($project) ?? 0);
    }

    private function coverMultiplierForProject(Project $project): float
    {
        return collect(['cover_1', 'cover_2', 'cover_3'])
            ->map(fn (string $field): float => (float) ($this->{$field} ?? $project->{$field} ?? 0))
            ->reduce(
                fn (float $multiplier, float $cover): float => $multiplier * max(0, 1 - ($cover / 100)),
                1.0,
            );
    }
}
