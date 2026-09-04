<?php

namespace App\Models;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use Database\Factories\DocumentPackTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
        static::deleted(function (DocumentPackTemplateItem $item): void {
            if (! $item->hasManagedFile()) {
                return;
            }

            $disk = $item->file_disk ?? 'local';

            try {
                if (! Storage::disk($disk)->delete($item->file_path)) {
                    Log::warning('Document pack template item was deleted, but its snapshot file could not be removed.', [
                        'document_pack_template_item_id' => $item->id,
                        'file_disk' => $disk,
                        'file_path' => $item->file_path,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Document pack template item was deleted, but snapshot storage cleanup failed.', [
                    'document_pack_template_item_id' => $item->id,
                    'file_disk' => $disk,
                    'file_path' => $item->file_path,
                    'exception' => $exception->getMessage(),
                ]);
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
