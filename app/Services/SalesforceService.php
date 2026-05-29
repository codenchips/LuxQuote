<?php

namespace App\Services;

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

        $where = filled($search)
            ? " WHERE Name LIKE '%".$this->soqlEscape($search)."%'"
            : '';

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

        if ($describe->failed()) {
            Log::error('Salesforce describe failed', ['status' => $describe->status()]);

            return ['success' => false, 'status' => $describe->status(), 'errors' => $describe->json()];
        }

        $fieldNames = array_column($describe->json()['fields'] ?? [], 'name');

        if (empty($fieldNames)) {
            return ['success' => false, 'status' => 0, 'errors' => ['No fields returned from describe']];
        }

        $result = $this->soqlQuery(
            $auth,
            'SELECT '.implode(', ', $fieldNames)." FROM Opportunity LIMIT {$limit}",
        );

        if ($result === null) {
            return ['success' => false, 'status' => 0, 'errors' => ['Query failed']];
        }

        return ['success' => true, 'records' => $result['records'] ?? []];
    }
}
