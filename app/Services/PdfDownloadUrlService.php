<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PdfDownloadUrlService
{
    private const TokenMinutes = 10;

    private const CleanupMinutes = 30;

    /**
     * @param  array{path: string, filename: string}  $pdf
     */
    public function register(array $pdf, int $userId): array
    {
        $token = Str::random(48);
        $filename = $this->sanitizeFilename($pdf['filename']);
        $path = $this->preparedPath($token);

        $this->cleanupExpiredFiles();
        File::ensureDirectoryExists(dirname($path));
        File::copy($pdf['path'], $path);
        File::delete($pdf['path']);

        Cache::put($this->cacheKey($token), [
            'path' => $path,
            'filename' => $filename,
            'user_id' => $userId,
        ], now()->addMinutes(self::TokenMinutes));

        return [
            'url' => route('pdf.downloads.show', [
                'token' => $token,
                'filename' => $filename,
            ]),
            'filename' => $filename,
        ];
    }

    public function response(string $token, int $userId): BinaryFileResponse
    {
        abort_if(blank($token) || ! preg_match('/^[A-Za-z0-9]{48}$/', $token), 404);

        $this->cleanupExpiredFiles();

        $download = Cache::get($this->cacheKey($token));

        abort_unless(is_array($download), 404);
        abort_unless(($download['user_id'] ?? null) === $userId, 403);

        $path = (string) ($download['path'] ?? '');
        $filename = $this->sanitizeFilename((string) ($download['filename'] ?? 'luxquote.pdf'));

        abort_unless(is_file($path), 404);

        return response()
            ->file($path, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]);
    }

    private function cacheKey(string $token): string
    {
        return 'pdf-download:'.$token;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(['\\', '/', '"'], '', $filename);

        return $filename !== '' ? $filename : 'luxquote.pdf';
    }

    private function preparedPath(string $token): string
    {
        return storage_path("app/pdf-downloads/{$token}.pdf");
    }

    private function cleanupExpiredFiles(): void
    {
        $directory = storage_path('app/pdf-downloads');

        if (! File::isDirectory($directory)) {
            return;
        }

        $oldestAllowedTimestamp = now()->subMinutes(self::CleanupMinutes)->getTimestamp();

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < $oldestAllowedTimestamp) {
                File::delete($file->getPathname());
            }
        }
    }
}
