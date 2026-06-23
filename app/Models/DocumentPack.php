<?php

namespace App\Models;

use Database\Factories\DocumentPackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['project_id', 'name', 'created_by', 'updated_by'])]
class DocumentPack extends Model
{
    /** @use HasFactory<DocumentPackFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (DocumentPack $documentPack): void {
            $documentPack->items()->get()->each(function (DocumentPackItem $item): void {
                if ($item->file_path !== null) {
                    Storage::disk($item->file_disk ?? 'local')->delete($item->file_path);
                }
            });
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentPackItem::class)->orderBy('sort_order');
    }
}
