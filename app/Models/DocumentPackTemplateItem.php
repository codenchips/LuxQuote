<?php

namespace App\Models;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use Database\Factories\DocumentPackTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'document_pack_template_id',
    'role',
    'source_type',
    'sort_order',
    'file_disk',
    'file_path',
    'original_filename',
    'configuration',
])]
class DocumentPackTemplateItem extends Model
{
    public const Directory = 'document-pack-templates';

    /** @use HasFactory<DocumentPackTemplateItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (DocumentPackTemplateItem $item): void {
            if ($item->hasManagedFile()) {
                Storage::disk($item->file_disk ?? 'local')->delete($item->file_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => DocumentPackItemRole::class,
            'source_type' => DocumentPackItemSource::class,
            'configuration' => 'array',
        ];
    }

    public function documentPackTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentPackTemplate::class);
    }

    public function hasManagedFile(): bool
    {
        if ($this->file_path === null) {
            return false;
        }

        $path = str_replace('\\', '/', $this->file_path);

        return str_starts_with($path, self::Directory.'/')
            && ! str_contains($path, '../')
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }
}
