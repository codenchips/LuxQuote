<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\Project;
use App\Models\SalesforceCustomerDefault;
use App\Services\SalesforceService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    public function mountInteractsWithTable(): void
    {
        parent::mountInteractsWithTable();

        $this->applyDefaultTeamFilter();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Project')
                ->visible(fn (): bool => auth()->user()?->can('projects.create') ?? false)
                ->slideOver()
                ->createAnother(false)
                ->mutateFormDataUsing(function (array $data): array {
                    abort_unless(auth()->user()?->can('projects.create'), 403);

                    $data['salesforce_project'] = (bool) ($data['salesforce_project'] ?? true);

                    if (! $data['salesforce_project']) {
                        abort_unless(auth()->user()?->can('projects.create-non-salesforce'), 403);
                    } elseif (blank($data['salesforce_id'] ?? null)) {
                        throw ValidationException::withMessages([
                            'salesforce_id' => 'Select a Salesforce project.',
                        ]);
                    }

                    $data['user_id'] = auth()->id();
                    $customerCoverDefaults = null;

                    if (! empty($data['salesforce_project']) && ! empty($data['salesforce_pending_data'])) {
                        $sfData = json_decode((string) $data['salesforce_pending_data'], true);

                        if (is_array($sfData) && ! empty($sfData['Name'])) {
                            $data['name'] = ProjectForm::salesforceProjectName($sfData['Name']);
                            $data['salesforce_id'] = $sfData['Id'] ?? $data['salesforce_id'] ?? null;
                            $data['owner_name'] = data_get($sfData, 'Owner.Name');
                            $data['branch_name'] = data_get($sfData, 'CEF_Branch__r.Name');
                            $hasSalesforceCover = filled($sfData['CEF_Cover__c'] ?? null);
                            $data['has_cover'] = $hasSalesforceCover;
                            $data['cover_direction'] = 'added';
                            $data['cover_1'] = $hasSalesforceCover ? $sfData['CEF_Cover__c'] : null;
                            $data['cover_2'] = $hasSalesforceCover ? '5.00' : null;
                            $data['cover_3'] = $hasSalesforceCover ? '0.00' : null;
                            $data['value'] = $sfData['Amount'] ?? $data['value'] ?? null;
                        }
                    }

                    if ($data['salesforce_project']) {
                        $opportunity = app(SalesforceService::class)->getOpportunityById($data['salesforce_id']);
                        if ($opportunity === null) {
                            throw ValidationException::withMessages(['salesforce_id' => 'The Salesforce project could not be verified. Select the project again.']);
                        }
                        if (filled($opportunity['AccountId'] ?? null)) {
                            $customerCoverDefaults = SalesforceCustomerDefault::coverValues($opportunity['AccountId']);
                        }
                    }

                    $data = ProjectForm::normaliseVisibilityData($data);

                    if ($customerCoverDefaults !== null) {
                        $data = array_replace($data, $customerCoverDefaults, ['has_cover' => true]);
                    }

                    return $data;
                })
                ->successRedirectUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),
        ];
    }

    protected function onValidationError(ValidationException $exception): void
    {
        parent::onValidationError($exception);

        $messages = collect($exception->errors())
            ->flatten()
            ->filter(fn (mixed $message): bool => is_string($message));

        if ($messages->isEmpty()) {
            return;
        }

        $isDuplicateProject = $messages->contains(
            fn (string $message): bool => str_contains($message, 'already exists'),
        );

        Notification::make()
            ->danger()
            ->title($isDuplicateProject ? 'Project already exists' : 'Project could not be created')
            ->body((string) $messages->first())
            ->send();
    }

    private function applyDefaultTeamFilter(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $preferredTeamId = $user->project_list_team_id;
        $currentTeamValues = $this->teamFilterValues();

        if ($preferredTeamId === null) {
            if ($this->filterValuesMatchCurrentUserTeams($currentTeamValues)) {
                unset($this->tableFilters['team']);
                session()->put($this->getTableFiltersSessionKey(), $this->tableFilters);
            }

            return;
        }

        $isMember = $user->teams()
            ->whereKey($preferredTeamId)
            ->exists();

        if (! $isMember) {
            return;
        }

        if ($currentTeamValues !== []
            && $currentTeamValues !== [(int) $preferredTeamId]
            && ! $this->filterValuesMatchCurrentUserTeams($currentTeamValues)) {
            return;
        }

        $this->tableFilters['team']['values'] = [(int) $preferredTeamId];
        session()->put($this->getTableFiltersSessionKey(), $this->tableFilters);
    }

    /**
     * @return array<int>
     */
    private function teamFilterValues(): array
    {
        return collect($this->tableFilters['team']['values'] ?? [])
            ->filter(fn (mixed $teamId): bool => filled($teamId))
            ->map(fn (mixed $teamId): int => (int) $teamId)
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $teamValues
     */
    private function filterValuesMatchCurrentUserTeams(array $teamValues): bool
    {
        if ($teamValues === []) {
            return false;
        }

        $currentTeamIds = auth()->user()?->teams()
            ->orderBy('name')
            ->pluck('teams.id')
            ->map(fn (int|string $teamId): int => (int) $teamId)
            ->values()
            ->all() ?? [];

        sort($teamValues);
        sort($currentTeamIds);

        return $teamValues === $currentTeamIds;
    }
}
