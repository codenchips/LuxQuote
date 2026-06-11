<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SalesforceService
{
    private const API_VERSION = 'v65.0';

    /**
     * Authenticate via OAuth2 Client Credentials and return the token + instance URL.
     *
     * @return array{token: string, instanceUrl: string}|null
     */
    private function authenticate(): ?array
    {
        $baseUrl = rtrim((string) config('services.salesforce.url', ''), '/');

        if (str_contains($baseUrl, '/services/data/')) {
            $baseUrl = explode('/services/data/', $baseUrl)[0];
        }

        $parsed = parse_url($baseUrl);
        $tokenUrl = sprintf(
            '%s://%s/services/oauth2/token',
            $parsed['scheme'] ?? 'https',
            $parsed['host'] ?? '',
        );

        $response = Http::asForm()->post($tokenUrl, [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.salesforce.client_id'),
            'client_secret' => config('services.salesforce.client_secret'),
        ]);

        if ($response->failed()) {
            Log::error('Salesforce authentication failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return [
            'token' => $response->json('access_token'),
            'instanceUrl' => rtrim((string) $response->json('instance_url'), '/'),
        ];
    }

    /**
     * Run a SOQL query and return the decoded JSON response.
     *
     * @param  array{token: string, instanceUrl: string}  $auth
     * @return array<string, mixed>|null
     */
    private function soqlQuery(array $auth, string $soql): ?array
    {
        $response = Http::withToken($auth['token'])
            ->acceptJson()
            ->get("{$auth['instanceUrl']}/services/data/".self::API_VERSION.'/query/', [
                'q' => $soql,
            ]);

        if ($response->failed()) {
            Log::error('Salesforce SOQL query failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'soql' => $soql,
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Escape a value for safe inclusion in a SOQL LIKE clause.
     */
    private function soqlEscape(string $value): string
    {
        return str_replace(['\\', "'", '%', '_'], ['\\\\', "\\'", '\\%', '\\_'], $value);
    }

    /**
     * Paginated, searchable, sortable list of Opportunity records.
     *
     * @param  string[]  $fields  SOQL field names to SELECT
     */
    public function getOpportunities(
        int $page = 1,
        int $perPage = 25,
        ?string $search = null,
        ?string $sortColumn = null,
        ?string $sortDirection = null,
        array $fields = ['Id', 'Name', 'StageName', 'CloseDate', 'Amount'],
    ): LengthAwarePaginator {
        $auth = $this->authenticate();

        if ($auth === null) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $where = $this->openOpportunityWhereClause(
            filled($search) ? "Name LIKE '%".$this->soqlEscape($search)."%'" : null,
        );

        // Allowlisted sort columns to prevent SOQL injection
        $allowedColumns = ['Name', 'StageName', 'CreatedDate', 'Amount', 'Project_Reference_Number__c', 'Owner.Name'];
        $orderBy = in_array($sortColumn, $allowedColumns, true)
            ? " ORDER BY {$sortColumn} ".(strtoupper($sortDirection ?? '') === 'DESC' ? 'DESC' : 'ASC')
            : ' ORDER BY CreatedDate DESC';

        $totalResult = $this->soqlQuery($auth, "SELECT COUNT() FROM Opportunity{$where}");
        $total = $totalResult['totalSize'] ?? 0;

        $offset = ($page - 1) * $perPage;
        $selectFields = implode(', ', $fields);
        $records = [];

        if ($total > 0) {
            $data = $this->soqlQuery(
                $auth,
                "SELECT {$selectFields} FROM Opportunity{$where}{$orderBy} LIMIT {$perPage} OFFSET {$offset}",
            );
            $records = $data['records'] ?? [];
        }

        return new LengthAwarePaginator($records, $total, $perPage, $page);
    }

    /**
     * Search Opportunities by name for typeahead Select.
     *
     * @return array<string, string> Keyed by Opportunity ID, value is display label.
     */
    public function searchOpportunities(string $query, int $limit = 10): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return [];
        }

        $escaped = $this->soqlEscape($query);
        $where = $this->openOpportunityWhereClause("Name LIKE '%{$escaped}%'");
        $result = $this->soqlQuery(
            $auth,
            "SELECT Id, Name, Project_Reference_Number__c FROM Opportunity{$where} ORDER BY Name ASC LIMIT {$limit}",
        );

        $options = [];

        foreach ($result['records'] ?? [] as $record) {
            $label = $record['Name'];

            if (! empty($record['Project_Reference_Number__c'])) {
                $label .= ' ('.$record['Project_Reference_Number__c'].')';
            }

            $options[$record['Id']] = $label;
        }

        return $options;
    }

    /**
     * Fetch a single Opportunity by Salesforce ID.
     *
     * @return array<string, mixed>|null
     */
    public function getOpportunityById(string $id): ?array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return null;
        }

        $escaped = $this->soqlEscape($id);
        $where = $this->openOpportunityWhereClause("Id = '{$escaped}'");
        $result = $this->soqlQuery(
            $auth,
            "SELECT Id, Name, Project_Reference_Number__c, CEF_Cover__c, Amount, Owner.Name, Owner.Email, Account.Name FROM Opportunity{$where} LIMIT 1",
        );

        return ($result['records'] ?? [])[0] ?? null;
    }

    public function updateOpportunityAmount(Project $project, float $amount): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return ['success' => false, 'message' => 'Salesforce authentication failed.'];
        }

        $opportunityId = $this->findOpportunityIdForProjectUsingAuth($project, $auth);

        if (blank($opportunityId)) {
            return ['success' => false, 'message' => 'No matching Salesforce Opportunity was found for this project.'];
        }

        $response = Http::withToken($auth['token'])
            ->acceptJson()
            ->patch("{$auth['instanceUrl']}/services/data/".self::API_VERSION."/sobjects/Opportunity/{$opportunityId}", [
                'Amount' => round($amount, 2),
            ]);

        if ($response->failed()) {
            Log::error('Salesforce Opportunity amount update failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'project_id' => $project->id,
                'opportunity_id' => $opportunityId,
                'amount' => $amount,
            ]);

            return ['success' => false, 'message' => $this->salesforceErrorMessage($response->json(), 'Salesforce Opportunity amount update failed')];
        }

        return [
            'success' => true,
            'opportunityId' => $opportunityId,
            'amount' => round($amount, 2),
        ];
    }

    public function findOpportunityIdForProject(Project $project): ?string
    {
        if (filled($project->salesforce_id)) {
            return $project->salesforce_id;
        }

        $auth = $this->authenticate();

        if ($auth === null) {
            return null;
        }

        return $this->findOpportunityIdForProjectUsingAuth($project, $auth);
    }

    /**
     * Upload a schedule PDF as a Salesforce file version attached to the project's Opportunity.
     *
     * @return array{success: bool, url?: string, contentVersionId?: string, contentDocumentId?: string, message?: string}
     */
    public function uploadSchedulePdf(Project $project, string $pdfContent, string $filename): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return ['success' => false, 'message' => 'Salesforce authentication failed.'];
        }

        $opportunityId = $this->findOpportunityIdForProjectUsingAuth($project, $auth);

        if (blank($opportunityId)) {
            return ['success' => false, 'message' => 'No matching Salesforce Opportunity was found for this project.'];
        }

        $title = pathinfo($filename, PATHINFO_FILENAME);
        $contentDocumentId = $this->findLinkedContentDocumentId($auth, $opportunityId, $title);
        $metadata = [
            'Title' => $title,
            'PathOnClient' => $filename,
        ];

        if ($contentDocumentId) {
            $metadata['ContentDocumentId'] = $contentDocumentId;
        } else {
            $metadata['FirstPublishLocationId'] = $opportunityId;
        }

        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);

        $response = Http::withToken($auth['token'])
            ->acceptJson()
            ->asMultipart()
            ->post("{$auth['instanceUrl']}/services/data/".self::API_VERSION.'/sobjects/ContentVersion', [
                [
                    'name' => 'entity_content',
                    'contents' => $metadataJson,
                    'headers' => ['Content-Type' => 'application/json'],
                ],
                [
                    'name' => 'VersionData',
                    'contents' => $pdfContent,
                    'filename' => $filename,
                    'headers' => ['Content-Type' => 'application/pdf'],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Salesforce file upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'project_id' => $project->id,
                'opportunity_id' => $opportunityId,
            ]);

            return ['success' => false, 'message' => $this->salesforceErrorMessage($response->json(), 'Salesforce file upload failed')];
        }

        $contentVersionId = (string) $response->json('id');
        $contentDocumentId ??= $this->findContentDocumentIdForVersion($auth, $contentVersionId);

        return [
            'success' => true,
            'url' => $contentDocumentId
                ? "{$auth['instanceUrl']}/lightning/r/ContentDocument/{$contentDocumentId}/view"
                : "{$auth['instanceUrl']}/lightning/r/Opportunity/{$opportunityId}/view",
            'contentVersionId' => $contentVersionId,
            'contentDocumentId' => $contentDocumentId,
        ];
    }

    private function salesforceErrorMessage(mixed $errors, string $fallback): string
    {
        if (is_array($errors) && isset($errors[0]['message'])) {
            return $fallback.': '.$errors[0]['message'];
        }

        return $fallback.'.';
    }

    private function openOpportunityWhereClause(?string $extraCondition = null): string
    {
        $conditions = [
            'IsClosed = false',
            'IsWon = false',
        ];

        if (filled($extraCondition)) {
            $conditions[] = $extraCondition;
        }

        return ' WHERE '.implode(' AND ', $conditions);
    }

    /**
     * @param  array{token: string, instanceUrl: string}  $auth
     */
    private function findOpportunityIdForProjectUsingAuth(Project $project, array $auth): ?string
    {
        if (filled($project->salesforce_id)) {
            return $project->salesforce_id;
        }

        if (blank($project->reference_number)) {
            return null;
        }

        $reference = $this->soqlEscape((string) $project->reference_number);
        $result = $this->soqlQuery(
            $auth,
            "SELECT Id FROM Opportunity WHERE Project_Reference_Number__c = '{$reference}' LIMIT 1",
        );

        return ($result['records'] ?? [])[0]['Id'] ?? null;
    }

    /**
     * @param  array{token: string, instanceUrl: string}  $auth
     */
    private function findLinkedContentDocumentId(array $auth, string $opportunityId, string $title): ?string
    {
        $escapedOpportunityId = $this->soqlEscape($opportunityId);
        $escapedTitle = $this->soqlEscape($title);
        $result = $this->soqlQuery(
            $auth,
            "SELECT ContentDocumentId FROM ContentDocumentLink WHERE LinkedEntityId = '{$escapedOpportunityId}' AND ContentDocument.Title = '{$escapedTitle}' ORDER BY SystemModstamp DESC LIMIT 1",
        );

        return ($result['records'] ?? [])[0]['ContentDocumentId'] ?? null;
    }

    /**
     * @param  array{token: string, instanceUrl: string}  $auth
     */
    private function findContentDocumentIdForVersion(array $auth, string $contentVersionId): ?string
    {
        $escapedContentVersionId = $this->soqlEscape($contentVersionId);
        $result = $this->soqlQuery(
            $auth,
            "SELECT ContentDocumentId FROM ContentVersion WHERE Id = '{$escapedContentVersionId}' LIMIT 1",
        );

        return ($result['records'] ?? [])[0]['ContentDocumentId'] ?? null;
    }

    /**
     * Fetch Opportunity records for the Artisan interrogator command.
     *
     * @return array{success: bool, records?: array<int, mixed>, status?: int, errors?: mixed}
     */
    public function fetchProjects(): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return ['success' => false, 'status' => 0, 'errors' => ['Authentication failed']];
        }

        $result = $this->soqlQuery(
            $auth,
            'SELECT Id, Name, StageName, CloseDate FROM Opportunity LIMIT 25',
        );

        if ($result === null) {
            return ['success' => false, 'status' => 0, 'errors' => ['Query failed']];
        }

        return ['success' => true, 'records' => $result['records'] ?? []];
    }

    /**
     * Describe the Opportunity object and fetch all fields dynamically.
     *
     * @return array{success: bool, records?: array<int, mixed>, status?: int, errors?: mixed}
     */
    public function fetchAllOpportunityFields(int $limit = 25): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return ['success' => false, 'status' => 0, 'errors' => ['Authentication failed']];
        }

        $describe = Http::withToken($auth['token'])
            ->acceptJson()
            ->get("{$auth['instanceUrl']}/services/data/".self::API_VERSION.'/sobjects/Opportunity/describe');
        //  ->get("{$auth['instanceUrl']}/services/data/".self::API_VERSION.'/sobjects/ContentVersion/describe');

        if ($describe->failed()) {
            Log::error('Salesforce describe failed', ['status' => $describe->status()]);

            return ['success' => false, 'status' => $describe->status(), 'errors' => $describe->json()];
        }

        $fieldNames = array_column($describe->json()['fields'] ?? [], 'name');

        // var_dump($fieldNames); // Debug output to verify field retrieval

        if (empty($fieldNames)) {
            return ['success' => false, 'status' => 0, 'errors' => ['No fields returned from describe']];
        }

        $result = $this->soqlQuery(
            $auth,
            'SELECT '.implode(', ', $fieldNames)." FROM Opportunity LIMIT {$limit}",
            // 'SELECT '.implode(', ', $fieldNames)." FROM ContentVersion LIMIT {$limit}",
        );

        if ($result === null) {
            return ['success' => false, 'status' => 0, 'errors' => ['Query failed']];
        }

        return ['success' => true, 'records' => $result['records'] ?? []];
    }
}
