<?php

namespace App\Http\Controllers;

use App\Enums\PermissionKey;
use App\Models\ResourceFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResourceFileController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ResourceFile $resourceFile): StreamedResponse
    {
        abort_unless($request->user()?->can(PermissionKey::ResourcesView->value), 403);
        abort_unless($resourceFile->hasManagedFile(), 404);

        try {
            $disk = Storage::disk(ResourceFile::Disk);
            abort_unless($disk->exists($resourceFile->file_path), 404);
            $fileSize = $disk->size($resourceFile->file_path);
            $stream = $disk->readStream($resourceFile->file_path);
        } catch (Throwable $exception) {
            Log::warning('A resource file could not be opened.', [
                'resource_file_id' => $resourceFile->id,
                'exception' => $exception->getMessage(),
            ]);

            abort(404);
        }

        abort_if($stream === false, 404);

        $filename = basename(str_replace('\\', '/', $resourceFile->original_filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?: 'resource.'.$resourceFile->extension;
        $fallbackFilename = Str::ascii($filename);
        $fallbackFilename = preg_replace('/[^A-Za-z0-9._-]/', '-', $fallbackFilename) ?: 'resource.'.$resourceFile->extension;
        $disposition = (new ResponseHeaderBag)->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $filename,
            $fallbackFilename,
        );

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => in_array($resourceFile->mime_type, ResourceFile::AcceptedMimeTypes, true)
                ? $resourceFile->mime_type
                : 'application/octet-stream',
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) $fileSize,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
