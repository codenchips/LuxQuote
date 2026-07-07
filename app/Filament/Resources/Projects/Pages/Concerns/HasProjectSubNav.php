<?php

namespace App\Filament\Resources\Projects\Pages\Concerns;

use App\Filament\Resources\Projects\Support\ProjectSubNavigation;
use Illuminate\Contracts\View\View;

trait HasProjectSubNav
{
    public function getHeader(): ?View
    {
        return view('filament.resources.projects.pages.project-header', [
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'actions' => $this->getCachedHeaderActions(),
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
            'subLinks' => ProjectSubNavigation::forProject($this->record),
        ]);
    }
}
