<?php

namespace App\Filament\Resources\Projects\Pages\Concerns;

use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\OutputProject;
use App\Filament\Resources\Projects\Pages\ProjectHistory;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\View\View;

trait HasProjectSubNav
{
    public function getHeader(): ?View
    {
        $isAdmin = auth()->user()?->role === UserRole::Admin;

        $subLinks = $isAdmin ? $this->buildProjectSubLinks() : [];

        return view('filament.resources.projects.pages.project-header', [
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'actions' => $this->getCachedHeaderActions(),
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
            'subLinks' => $subLinks,
        ]);
    }

    /** @return array<array{label: string, url: string, icon: string, active: bool}> */
    private function buildProjectSubLinks(): array
    {
        $recordId = $this->record->getKey();
        $currentUrl = request()->url();

        $links = [
            ['label' => 'Edit', 'icon' => 'heroicon-o-pencil-square', 'url' => ProjectResource::getUrl('view', ['record' => $recordId])],
            ['label' => 'Validation', 'icon' => 'heroicon-o-shield-check', 'url' => ValidationProject::getUrl(['record' => $recordId])],
            ['label' => 'Output', 'icon' => 'heroicon-o-arrow-down-tray', 'url' => OutputProject::getUrl(['record' => $recordId])],
            ['label' => 'Project History', 'icon' => 'heroicon-o-clock', 'url' => ProjectHistory::getUrl(['record' => $recordId])],
        ];

        return array_map(function (array $item) use ($currentUrl): array {
            return [
                'label' => $item['label'],
                'url' => $item['url'],
                'icon' => $item['icon'],
                'active' => rtrim($currentUrl, '/') === rtrim($item['url'], '/'),
            ];
        }, $links);
    }
}
