<?php

namespace App\Filament\Resources\Projects\Pages\Concerns;

use App\Filament\Resources\Projects\Support\ProjectSubNavigation;
use App\Models\ProjectRevision;
use App\Models\User;
use App\Services\SalesforceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    protected function projectRevisionLabelWithOwner(?int $revisionNumber): ?string
    {
        if ($revisionNumber === null) {
            return null;
        }

        $revisionLabel = ProjectRevision::labelForNumber($revisionNumber);

        if ($ownerName = $this->projectOwnerNameForHeader()) {
            $revisionLabel .= " ({$ownerName})";
        }

        return $revisionLabel;
    }

    protected function projectOwnerNameForHeader(): ?string
    {
        if (filled($this->record->salesforce_id)) {
            $owner = Cache::remember(
                'project-owner-name.salesforce.'.md5((string) $this->record->salesforce_id),
                now()->addHours(6),
                fn (): ?array => $this->salesforceOwnerForHeader(),
            );

            if (filled($owner['name'] ?? null)) {
                return (string) $owner['name'];
            }
        }

        if (blank($this->record->owner_email)) {
            return null;
        }

        $owner = User::query()
            ->where('email', (string) $this->record->owner_email)
            ->first();

        return filled($owner?->name) ? $owner->name : null;
    }

    /**
     * @return array{id: string, name: string|null, email: string|null}|null
     */
    private function salesforceOwnerForHeader(): ?array
    {
        try {
            return app(SalesforceService::class)->getOpportunityOwner((string) $this->record->salesforce_id);
        } catch (Throwable $exception) {
            Log::warning('Salesforce owner lookup failed during project header render', [
                'project_id' => $this->record->id,
                'project_reference' => $this->record->reference_number,
                'salesforce_id' => $this->record->salesforce_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
