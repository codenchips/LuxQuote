<?php

namespace App\Models;

use Database\Factories\ReportingEventProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reporting_event_id', 'product_id', 'code', 'description', 'quantity'])]
class ReportingEventProduct extends Model
{
    /** @use HasFactory<ReportingEventProductFactory> */
    use HasFactory;

    public $timestamps = false;

    public function reportingEvent(): BelongsTo
    {
        return $this->belongsTo(ReportingEvent::class);
    }
}
