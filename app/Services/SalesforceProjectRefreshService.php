<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SalesforceProjectRefreshService
{
    public function __construct(
        private readonly SalesforceService $salesforce,
        private readonly ProjectNameFormatter $projectNameFormatter,
    ) {}

    /**
     * @return array{success: bool, message: string, attributes?: array<string, mixed>}
     */
    public function refresh(Project $project): array
    {
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

        $attributes = $this->refreshableAttributes($project, $opportunity);
        $conflict = $this->conflictingProjectField($project, $attributes);

        if ($conflict !== null) {
            return [
                'success' => false,
                'message' => "The Salesforce {$conflict} is already used by another LuxQuote project. No details were changed.",
            ];
        }

        try {
            if ($attributes !== []) {
                DB::transaction(fn (): bool => $project->update($attributes));
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

        return [
            'success' => true,
            'message' => $attributes !== []
                ? 'The latest Salesforce project details have been applied. LuxQuote Value was preserved.'
                : 'The LuxQuote project details already match Salesforce. LuxQuote Value was preserved.',
            'attributes' => $attributes,
        ];
    }

    /**
     * Build only fields owned by Salesforce. Value/Amount and LuxQuote-owned fields
     * are deliberately absent from this list.
     *
     * @param  array<string, mixed>  $opportunity
     * @return array<string, mixed>
     */
    private function refreshableAttributes(Project $project, array $opportunity): array
    {
        $attributes = [];

        if (filled($opportunity['Name'] ?? null)) {
            $attributes['name'] = $this->limit(
                $this->projectNameFormatter->fromSalesforce((string) $opportunity['Name']),
            );
        }

        if (filled($opportunity['Project_Reference_Number__c'] ?? null)) {
            $attributes['reference_number'] = $this->limit((string) $opportunity['Project_Reference_Number__c']);
        }

        $customerName = $this->customerName($opportunity);

        if (filled($customerName)) {
            $attributes['customer_name'] = $this->limit($customerName);
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

        return array_filter(
            $attributes,
            fn (mixed $value, string $key): bool => $project->getAttribute($key) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
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

        return null;
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
