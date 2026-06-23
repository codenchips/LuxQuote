<?php

namespace App\Models;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use Database\Factories\DocumentPackItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'document_pack_id',
    'role',
    'source_type',
    'sort_order',
    'file_disk',
    'file_path',
    'original_filename',
    'configuration',
])]
class DocumentPackItem extends Model
{
    /** @use HasFactory<DocumentPackItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => DocumentPackItemRole::class,
            'source_type' => DocumentPackItemSource::class,
            'configuration' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (DocumentPackItem $item): void {
            if ($item->file_path !== null) {
                Storage::disk($item->file_disk ?? 'local')->delete($item->file_path);
            }
        });
    }

    public function documentPack(): BelongsTo
    {
        return $this->belongsTo(DocumentPack::class);
    }
}
