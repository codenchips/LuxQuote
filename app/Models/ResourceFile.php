<?php

namespace App\Models;

use Database\Factories\ResourceFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'display_name',
    'file_disk',
    'file_path',
    'original_filename',
    'mime_type',
    'extension',
    'file_size',
    'uploaded_by_id',
])]
class ResourceFile extends Model
{
    /** @use HasFactory<ResourceFileFactory> */
    use HasFactory;

    public const Disk = 'local';

    public const Directory = 'resources';

    public const MaxUploadSizeKilobytes = 10240;

    /** @var array<int, string> */
    public const AcceptedExtensions = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'csv',
        'txt',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    /** @var array<int, string> */
    public const AcceptedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/csv',
        'text/plain',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (ResourceFile $resourceFile): void {
            if (! $resourceFile->hasManagedFile()) {
                Log::warning('Skipped deletion of an unmanaged resource file path.', [
                    'resource_file_id' => $resourceFile->id,
                    'file_disk' => $resourceFile->file_disk,
                    'file_path' => $resourceFile->file_path,
                ]);

                return;
            }

            try {
                if (! Storage::disk(self::Disk)->delete($resourceFile->file_path)) {
                    Log::warning('Resource metadata was deleted, but its stored file could not be removed.', [
                        'resource_file_id' => $resourceFile->id,
                        'file_path' => $resourceFile->file_path,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Resource metadata was deleted, but storage cleanup failed.', [
                    'resource_file_id' => $resourceFile->id,
                    'file_path' => $resourceFile->file_path,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function hasManagedFile(): bool
    {
        return $this->file_disk === self::Disk
            && self::isManagedFilePath($this->file_path);
    }

    public function isBrowserPreviewable(): bool
    {
        return in_array($this->extension, [
            'pdf',
            'csv',
            'txt',
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
        ], true);
    }

    public static function isManagedFilePath(?string $filePath): bool
    {
        if ($filePath === null || str_contains($filePath, '..') || str_contains($filePath, '\\')) {
            return false;
        }

        return preg_match('#^'.preg_quote(self::Directory, '#').'/[A-Za-z0-9_-]+\.[A-Za-z0-9]+$#', $filePath) === 1;
    }
}
