<?php

namespace App\Services;

use App\Enums\ProjectRevisionStatus;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class SalesforceProjectRefreshService
{
    public function __construct(
        private readonly SalesforceService $salesforce,
        private readonly ProjectNameFormatter $projectNameFormatter,
    ) {}

    /**
     * @return array{success: bool, message: string, token?: string, attributes?: array<string, mixed>}
     */
    public function preview(Project $project): array
    {
        $project->refresh();
        $this->authorizeRefresh($project);
        if (! $project->salesforce_project || blank($project->salesforce_id)) {
            return [
                'success' => false,
                'message' => 'This project is not linked to a Salesforce project.',
            ];
        }

        try {
            $opportunity = $this->salesforce->getOpportunityById((string) $project->salesforce_id);
        } catch (Throwable $exception) {
            Log::error('Salesforce project refresh request failed', [
                'project_id' => $project->getKey(),
                'salesforce_id' => $project->salesforce_id,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Salesforce could not be reached. No LuxQuote details were changed.',
            ];
        }

        if ($opportunity === null) {
            return [
                'success' => false,
                'message' => 'Salesforce project details could not be fetched. No LuxQuote details were changed.',
            ];
        }

        $attributes = $this->refreshableAttributes($opportunity);

        $token = (string) Str::uuid();
        Cache::put('salesforce-project-refresh:'.$token, [
            'project_id' => $project->getKey(),
            'salesforce_id' => $project->salesforce_id,
            'user_id' => auth()->id(),
            'attributes' => $attributes,
            'original' => $project->only(array_keys($attributes)),
        ], now()->addMinutes(15));

        return [
            'success' => true,
            'message' => 'Choose which fields to replace with the fetched Salesforce values.',
            'token' => $token,
            'attributes' => $attributes,
        ];
    }

    /**
     * @param  array<int, string>  $selectedFields
     * @return array{success: bool, message: string, attributes?: array<string, mixed>}
     */
    public function refresh(Project $project, string $token, array $selectedFields): array
    {
        $this->authorizeRefresh($project);
        $preview = $this->storedPreview($project, $token);

        if ($preview === null) {
            return ['success' => false, 'message' => 'This refresh preview has expired. Fetch the Salesforce details again.'];
        }

        $available = $this->permittedAttributes($preview['attributes']);

        if (array_diff($selectedFields, array_keys($available)) !== []) {
            return ['success' => false, 'message' => 'The selected fields are not available for refresh. Fetch the details again.'];
        }

        $attributes = Arr::only($available, $selectedFields);

        if (Validator::make($attributes, ['reference_number' => ['sometimes', 'alpha_dash:ascii']])->fails()) {
            return [
                'success' => false,
                'message' => 'The Salesforce reference number may only contain letters, numbers, hyphens and underscores. No details were changed.',
            ];
        }

        if (Validator::make($attributes, ['value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.99']])->fails()) {
            return ['success' => false, 'message' => 'The Salesforce Amount is not a valid project value. No details were changed.'];
        }

        $conflict = $this->conflictingProjectField($project, $attributes);

        if ($conflict !== null) {
            return [
                'success' => false,
                'message' => "The Salesforce {$conflict} is already used by another LuxQuote project. No details were changed.",
            ];
        }

        try {
            if ($attributes !== []) {
                $saved = DB::transaction(function () use ($project, $attributes, $preview): bool {
                    $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->getKey());
                    $this->authorizeRefresh($lockedProject);

                    foreach (array_keys($attributes) as $field) {
                        if ($lockedProject->getAttribute($field) !== $preview['original'][$field]) {
                            return false;
                        }
                    }

                    return $lockedProject->update($attributes);
                });

                if (! $saved) {
                    return ['success' => false, 'message' => 'The selected project details changed after the preview. Fetch the details again.'];
                }
            }
        } catch (Throwable $exception) {
            Log::error('Salesforce project refresh could not update LuxQuote', [
                'project_id' => $project->getKey(),
                'salesforce_id' => $project->salesforce_id,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'The refreshed details could not be saved. No LuxQuote details were changed.',
            ];
        }

        Cache::forget('salesforce-project-refresh:'.$token);

        return [
            'success' => true,
            'message' => $attributes !== []
                ? 'The selected Salesforce project details have been applied.'
                : 'No fields were selected. Project details were left unchanged.',
            'attributes' => $attributes,
        ];
    }

    /**
     * Build every available field populated by the original Salesforce import.
     *
     * @param  array<string, mixed>  $opportunity
     * @return array<string, mixed>
     */
    private function refreshableAttributes(array $opportunity): array
    {
        $attributes = [];

        if (filled($opportunity['Name'] ?? null)) {
            $attributes['name'] = $this->projectNameFormatter->fromSalesforce((string) $opportunity['Name']);
        }

        if (filled($opportunity['Project_Reference_Number__c'] ?? null)) {
            $attributes['reference_number'] = $this->limit((string) $opportunity['Project_Reference_Number__c']);
        }

        $customerName = $this->customerName($opportunity);

        if (filled($customerName)) {
            $attributes['customer_name'] = $this->limit($customerName);
        }

        if (array_key_exists('Amount', $opportunity)) {
            $amount = $opportunity['Amount'];
            $attributes['value'] = is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : $amount;
        }

        if (Arr::has($opportunity, 'Owner.Email')) {
            $attributes['owner_email'] = $this->nullableLimitedString(
                str_replace('.invalid', '', (string) data_get($opportunity, 'Owner.Email')),
            );
        }

        if (Arr::has($opportunity, 'Owner.Name')) {
            $attributes['owner_name'] = $this->nullableLimitedString(
                (string) data_get($opportunity, 'Owner.Name'),
            );
        }

        if (Arr::has($opportunity, 'CEF_Branch__r.Name')) {
            $attributes['branch_name'] = $this->nullableLimitedString(
                (string) data_get($opportunity, 'CEF_Branch__r.Name'),
            );
        }

        if (array_key_exists('CEF_Cover__c', $opportunity)) {
            $salesforceCover = $opportunity['CEF_Cover__c'];
            $hasCover = filled($salesforceCover) && is_numeric($salesforceCover);
            $attributes['has_cover'] = $hasCover;
            $attributes['cover_direction'] = 'deducted';
            $attributes['cover_1'] = $hasCover
                ? number_format(min(999.99, max(0, (float) $salesforceCover)), 2, '.', '')
                : null;
            $attributes['cover_2'] = $hasCover ? '5.00' : null;
            $attributes['cover_3'] = $hasCover ? '0.00' : null;
        }

        return $this->permittedAttributes($attributes);
    }

    /** @return array<string, string> */
    public function fieldLabels(Project $project, string $token): array
    {
        $preview = $this->storedPreview($project, $token);

        return Arr::only([
            'name' => 'Project Name',
            'reference_number' => 'Reference Number',
            'customer_name' => 'Customer Name',
            'value' => 'Value (Salesforce Amount)',
            'owner_email' => 'Project Owner (email)',
            'owner_name' => 'Project Owner (name)',
            'branch_name' => 'Branch Name',
            'has_cover' => 'Has Cover',
            'cover_direction' => 'Cover Direction',
            'cover_1' => 'Cover 1',
            'cover_2' => 'Cover 2',
            'cover_3' => 'Cover 3',
        ], array_keys($this->permittedAttributes($preview['attributes'] ?? [])));
    }

    /** @return array<string, string> */
    public function fieldDescriptions(Project $project, string $token): array
    {
        $preview = $this->storedPreview($project, $token);
        $descriptions = [];

        foreach ($this->fieldLabels($project, $token) as $field => $label) {
            $descriptions[$field] = 'Current: '.$this->displayValue($preview['original'][$field])
                .' → Salesforce: '.$this->displayValue($preview['attributes'][$field]);
        }

        return $descriptions;
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return $value === null || $value === '' ? '(blank)' : (string) $value;
    }

    private function authorizeRefresh(Project $project): void
    {
        abort_unless(auth()->user()?->can('projects.update-details'), 403);
        abort_if($project->activeRevision?->status === ProjectRevisionStatus::Approved, 403, 'Approved revisions are locked against editing.');
    }

    /** @return array<string, mixed>|null */
    private function storedPreview(Project $project, string $token): ?array
    {
        $this->authorizeRefresh($project);
        $preview = Cache::get('salesforce-project-refresh:'.$token);

        if (! is_array($preview)
            || ($preview['project_id'] ?? null) !== $project->getKey()
            || ($preview['salesforce_id'] ?? null) !== $project->salesforce_id
            || ($preview['user_id'] ?? null) !== auth()->id()) {
            return null;
        }

        return $preview;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function permittedAttributes(array $attributes): array
    {
        if (! (auth()->user()?->can('pricing.view') && auth()->user()?->can('pricing.update'))) {
            unset($attributes['value']);
        }

        if (! (auth()->user()?->can('pricing.view') && auth()->user()?->can('cover.update'))) {
            return Arr::except($attributes, ['has_cover', 'cover_direction', 'cover_1', 'cover_2', 'cover_3']);
        }

        return $attributes;
    }

    /** @param array<string, mixed> $opportunity */
    private function customerName(array $opportunity): ?string
    {
        if (filled($opportunity['Miscellaneous_Customer_Name__c'] ?? null)) {
            return (string) $opportunity['Miscellaneous_Customer_Name__c'];
        }

        if (filled(data_get($opportunity, 'Account.Name'))) {
            return (string) data_get($opportunity, 'Account.Name');
        }

        return filled($opportunity['Name'] ?? null) ? (string) $opportunity['Name'] : null;
    }

    /** @param array<string, mixed> $attributes */
    private function conflictingProjectField(Project $project, array $attributes): ?string
    {
        foreach (['name' => 'project name', 'reference_number' => 'reference number'] as $field => $label) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            if (Project::query()
                ->where($field, $attributes[$field])
                ->where($project->getKeyName(), '!=', $project->getKey())
                ->exists()) {
                return $label;
            }
        }

        return null;
    }

    private function nullableLimitedString(string $value): ?string
    {
        return filled($value) ? $this->limit($value) : null;
    }

    private function limit(string $value): string
    {
        return mb_substr($value, 0, 255);
    }
}
