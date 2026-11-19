<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\Process\Process;

class ProjectDesignCoverPdfService
{
    public function builder(Project $project): PdfBuilder
    {
        $assets = [];

        foreach (['top.jpg', 'left.jpg', 'right.jpg', 'logo-white.png'] as $filename) {
            $mime = str_ends_with($filename, '.png') ? 'image/png' : 'image/jpeg';
            $assets[$filename] = 'data:'.$mime.';base64,'.base64_encode(File::get(public_path('images/design-cover/'.$filename)));
        }

        return Pdf::view('pdfs.design-cover', [
            'project' => $project->loadMissing('user'),
            'assets' => $assets,
            'fonts' => collect(['Light', 'Regular', 'SemiBold'])->mapWithKeys(fn (string $weight): array => [$weight => base64_encode(File::get(public_path('fonts/chivo/Chivo-'.$weight.'.ttf')))])->all(),
            'date' => now()->format('d/m/Y'),
        ])
            ->format('A4')
            ->margins(0, 0, 0, 0)
            ->withBrowsershot(function (object $browsershot): void {
                app(ProjectSchedulePdfService::class)->configureBrowsershot($browsershot);
                $browsershot->noSandbox()->showBackground();
            });
    }

    public function content(Project $project): string
    {
        return base64_decode($this->builder($project)->base64(), true) ?: throw new RuntimeException('The design cover could not be rendered.');
    }

    public function prependTo(string $scheduleContent, Project $project): string
    {
        $directory = storage_path('app/private/design-cover/'.Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put($directory.'/cover.pdf', $this->content($project));
            File::put($directory.'/schedule.pdf', $scheduleContent);
            $process = new Process(['qpdf', '--empty', '--pages', $directory.'/cover.pdf', '1-z', $directory.'/schedule.pdf', '1-z', '--', $directory.'/combined.pdf']);
            $process->setTimeout(120)->run();

            if (! in_array($process->getExitCode(), [0, 3], true) || ! File::exists($directory.'/combined.pdf')) {
                throw new RuntimeException('The design cover could not be added to the schedule.');
            }

            return File::get($directory.'/combined.pdf');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function templateHash(): string
    {
        $paths = [resource_path('views/pdfs/design-cover.blade.php'), resource_path('views/pdfs/brand-panel.blade.php'), resource_path('views/pdfs/brand-panel-styles.blade.php'), public_path('fonts/chivo/Chivo-Light.ttf'), public_path('fonts/chivo/Chivo-Regular.ttf'), public_path('fonts/chivo/Chivo-SemiBold.ttf')];

        foreach (['top.jpg', 'left.jpg', 'right.jpg', 'logo-white.png'] as $filename) {
            $paths[] = public_path('images/design-cover/'.$filename);
        }

        return hash('sha256', implode('', array_map(fn (string $path): string => hash_file('sha256', $path), $paths)));
    }
}
