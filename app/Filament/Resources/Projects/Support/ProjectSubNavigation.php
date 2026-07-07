<?php

namespace App\Filament\Resources\Projects\Support;

use App\Filament\Resources\Projects\Pages\OutputProject;
use App\Filament\Resources\Projects\Pages\ProjectHistory;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectSubNavigation
{
    /**
     * @return array<array{label: string, url: string, icon: string, active: bool}>
     */
    public static function forCurrentRequest(): array
    {
        $request = request();

        if (! $request instanceof Request || ! $request->routeIs(...self::routeNames())) {
            return [];
        }

        $record = $request->route('record');

        if (! $record instanceof Project) {
            $record = filled($record)
                ? ProjectResource::resolveRecordRouteBinding((string) $record)
                : null;
        }

        if (! $record instanceof Project) {
            return [];
        }

        return self::forProject($record, $request->url());
    }

    /**
     * @return array<array{label: string, url: string, icon: string, active: bool}>
     */
    public static function forProject(Project $project, ?string $currentUrl = null): array
    {
        $currentUrl ??= request()->url();
        $user = auth()->user();
        $links = [];

        if ($user?->can('projects.view')) {
            $links[] = [
                'label' => 'Edit',
                'icon' => 'heroicon-o-pencil-square',
                'url' => ProjectResource::getUrl('view', ['record' => $project]),
            ];
        }

        if ($user?->can('validation.view')) {
            $links[] = [
                'label' => 'Validation',
                'icon' => 'heroicon-o-shield-check',
                'url' => ValidationProject::getUrl(['record' => $project]),
            ];
        }

        if ($user?->can('output.view')) {
            $links[] = [
                'label' => 'Output',
                'icon' => 'heroicon-o-arrow-down-tray',
                'url' => OutputProject::getUrl(['record' => $project]),
            ];
        }

        if ($user?->can('project-history.view')) {
            $links[] = [
                'label' => 'Project History',
                'icon' => 'heroicon-o-clock',
                'url' => ProjectHistory::getUrl(['record' => $project]),
            ];
        }

        return array_map(function (array $item) use ($currentUrl): array {
            return [
                'label' => $item['label'],
                'url' => $item['url'],
                'icon' => $item['icon'],
                'active' => rtrim($currentUrl, '/') === rtrim($item['url'], '/'),
            ];
        }, $links);
    }

    /**
     * @return array<int, string>
     */
    private static function routeNames(): array
    {
        return [
            ViewProject::getRouteName(),
            ValidationProject::getRouteName(),
            OutputProject::getRouteName(),
            ProjectHistory::getRouteName(),
        ];
    }
}
