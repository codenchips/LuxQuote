<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

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

        return $this->registerFile(
            path: $pdf['path'],
            filename: $filename,
            userId: $userId,
            mimeType: 'application/pdf',
            disposition: 'inline',
            extension: 'pdf',
            token: $token,
        );
    }

    /**
     * @param  array<int, string>  $tokens
     */
    public function registerZip(array $tokens, int $userId, string $filename): array
    {
        $this->cleanupExpiredFiles();

        $downloads = collect($tokens)
            ->map(fn (string $token): ?array => $this->metadata($token, $userId))
            ->filter()
            ->values();

        abort_if($downloads->isEmpty(), 404);

        $zipToken = Str::random(48);
        $zipPath = $this->preparedPath($zipToken, 'zip');
        File::ensureDirectoryExists(dirname($zipPath));

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The quote ZIP file could not be created.');
        }

        $filesAdded = 0;

        foreach ($downloads as $download) {
            $path = (string) ($download['path'] ?? '');

            if (! is_file($path)) {
                continue;
            }

            $zip->addFile($path, $this->sanitizeFilename((string) ($download['filename'] ?? basename($path))));
            $filesAdded++;
        }

        $zip->close();

        if ($filesAdded === 0) {
            File::delete($zipPath);

            abort(404, 'The prepared quote PDFs are no longer available.');
        }

        $filename = $this->sanitizeFilename($filename);

        Cache::put($this->cacheKey($zipToken), [
            'path' => $zipPath,
            'filename' => $filename,
            'user_id' => $userId,
            'mime_type' => 'application/zip',
            'disposition' => 'attachment',
        ], now()->addMinutes(self::TokenMinutes));

        return [
            'url' => route('pdf.downloads.show', [
                'token' => $zipToken,
                'filename' => $filename,
            ]),
            'filename' => $filename,
            'token' => $zipToken,
        ];
    }

    public function metadata(string $token, int $userId): ?array
    {
        abort_if(blank($token) || ! preg_match('/^[A-Za-z0-9]{48}$/', $token), 404);

        $download = Cache::get($this->cacheKey($token));

        if (! is_array($download) || ($download['user_id'] ?? null) !== $userId) {
            return null;
        }

        return $download;
    }

    private function registerFile(
        string $path,
        string $filename,
        int $userId,
        string $mimeType,
        string $disposition,
        string $extension,
        bool $deleteOriginal = true,
        ?string $token = null,
    ): array {
        $token ??= Str::random(48);
        $preparedPath = $this->preparedPath($token, $extension);

        $this->cleanupExpiredFiles();
        File::ensureDirectoryExists(dirname($preparedPath));
        File::copy($path, $preparedPath);

        if ($deleteOriginal) {
            File::delete($path);
        }

        Cache::put($this->cacheKey($token), [
            'path' => $preparedPath,
            'filename' => $filename,
            'user_id' => $userId,
            'mime_type' => $mimeType,
            'disposition' => $disposition,
        ], now()->addMinutes(self::TokenMinutes));

        return [
            'url' => route('pdf.downloads.show', [
                'token' => $token,
                'filename' => $filename,
            ]),
            'filename' => $filename,
            'token' => $token,
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
        $mimeType = (string) ($download['mime_type'] ?? 'application/pdf');
        $disposition = (string) ($download['disposition'] ?? 'inline');

        abort_unless(is_file($path), 404);

        return response()
            ->file($path, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
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

    private function preparedPath(string $token, string $extension): string
    {
        return storage_path("app/pdf-downloads/{$token}.{$extension}");
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
