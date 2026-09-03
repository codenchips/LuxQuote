<?php

namespace App\Filament\Resources\ResourceFiles\Pages;

use App\Filament\Resources\ResourceFiles\ResourceFileResource;
use App\Models\ResourceFile;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateResourceFile extends CreateRecord
{
    protected static string $resource = ResourceFileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $filePath = $data['file_path'] ?? null;

        if (! ResourceFile::isManagedFilePath($filePath)) {
            throw ValidationException::withMessages([
                'data.file_path' => 'The uploaded file could not be stored safely.',
            ]);
        }

        $disk = Storage::disk(ResourceFile::Disk);

        try {
            if (! $disk->exists($filePath)) {
                throw ValidationException::withMessages([
                    'data.file_path' => 'The uploaded file is no longer available. Please upload it again.',
                ]);
            }

            $mimeType = $disk->mimeType($filePath);
            $fileSize = $disk->size($filePath);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'data.file_path' => 'The uploaded file could not be inspected. Please try again.',
            ]);
        }

        $originalFilename = basename(str_replace('\\', '/', (string) ($data['original_filename'] ?? 'resource')));
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (
            ! in_array($extension, ResourceFile::AcceptedExtensions, true)
            || ! is_string($mimeType)
            || ! in_array($mimeType, ResourceFile::AcceptedMimeTypes, true)
        ) {
            throw ValidationException::withMessages([
                'data.file_path' => 'This file type is not supported.',
            ]);
        }

        return [
            ...$data,
            'file_disk' => ResourceFile::Disk,
            'original_filename' => mb_substr($originalFilename, 0, 255),
            'mime_type' => mb_substr($mimeType, 0, 150),
            'extension' => $extension,
            'file_size' => $fileSize,
            'uploaded_by_id' => auth()->id(),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (Throwable $exception) {
            $filePath = $data['file_path'] ?? null;

            if (ResourceFile::isManagedFilePath($filePath)) {
                try {
                    Storage::disk(ResourceFile::Disk)->delete($filePath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    protected function getRedirectUrl(): string
    {
        return ResourceFileResource::getUrl('index');
    }
}
