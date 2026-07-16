<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class SalesforceService
{
    private const API_VERSION = 'v65.0';

    private const AUTH_METHOD_CLIENT_CREDENTIALS = 'client_credentials';

    private const AUTH_METHOD_JWT_BEARER = 'jwt_bearer';

    private const JWT_BEARER_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private const AUTH_CACHE_TTL_BUFFER_SECONDS = 60;

    private const AUTH_CACHE_DEFAULT_SECONDS = 3600;

    /**
     * Authenticate and return the token + instance URL.
     *
     * @return array{token: string, instanceUrl: string}|null
     */
    private function authenticate(): ?array
    {
        return match ((string) config('services.salesforce.auth_method', self::AUTH_METHOD_CLIENT_CREDENTIALS)) {
            self::AUTH_METHOD_JWT_BEARER, 'jwt' => $this->authenticateWithJwtBearer(),
            default => $this->authenticateWithClientCredentials(),
        };
    }

    /**
     * Authenticate via OAuth2 Client Credentials and return the token + instance URL.
     *
     * @return array{token: string, instanceUrl: string}|null
     */
    private function authenticateWithClientCredentials(): ?array
    {
        $baseUrl = rtrim((string) config('services.salesforce.url', ''), '/');
        $tokenUrl = $this->tokenUrl($baseUrl);
        $cacheKey = $this->authCacheKey($tokenUrl, [
            self::AUTH_METHOD_CLIENT_CREDENTIALS,
            (string) config('services.salesforce.client_id'),
        ]);

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['token'], $cached['instanceUrl'])) {
            return [
                'token' => (string) $cached['token'],
                'instanceUrl' => (string) $cached['instanceUrl'],
            ];
        }

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

        $auth = [
            'token' => (string) $response->json('access_token'),
            'instanceUrl' => rtrim((string) $response->json('instance_url'), '/'),
        ];

        Cache::put($cacheKey, $auth, $this->authCacheSeconds((int) $response->json('expires_in', self::AUTH_CACHE_DEFAULT_SECONDS)));

        return $auth;
    }

    /**
     * Authenticate via OAuth2 JWT Bearer flow and return the token + instance URL.
     *
     * @return array{token: string, instanceUrl: string}|null
     */
    private function authenticateWithJwtBearer(): ?array
    {
        $clientId = (string) config('services.salesforce.client_id');
        $subject = (string) config('services.salesforce.jwt_subject');
        $audience = $this->jwtAudience();
        $privateKey = $this->jwtPrivateKey();

        if (blank($clientId) || blank($subject) || blank($audience) || blank($privateKey)) {
            Log::error('Salesforce JWT authentication is not configured.');

            return null;
        }

        $tokenUrl = $this->tokenUrl($audience);
        $cacheKey = $this->authCacheKey($tokenUrl, [
            self::AUTH_METHOD_JWT_BEARER,
            $clientId,
            $subject,
            $audience,
        ]);

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['token'], $cached['instanceUrl'])) {
            return [
                'token' => (string) $cached['token'],
                'instanceUrl' => (string) $cached['instanceUrl'],
            ];
        }

        $assertion = $this->jwtAssertion($clientId, $subject, $audience, $privateKey);

        if ($assertion === null) {
            return null;
        }

        $response = Http::asForm()->post($tokenUrl, [
            'grant_type' => self::JWT_BEARER_GRANT_TYPE,
            'assertion' => $assertion,
        ]);

        if ($response->failed()) {
            Log::error('Salesforce JWT authentication failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $auth = [
            'token' => (string) $response->json('access_token'),
            'instanceUrl' => rtrim((string) $response->json('instance_url'), '/'),
        ];

        Cache::put($cacheKey, $auth, $this->authCacheSeconds((int) $response->json('expires_in', self::AUTH_CACHE_DEFAULT_SECONDS)));

        return $auth;
    }

    /**
     * @param  string[]  $parts
     */
    private function authCacheKey(string $tokenUrl, array $parts): string
    {
        return 'salesforce.auth.'.hash('sha256', implode('|', [$tokenUrl, ...$parts]));
    }

    private function authCacheSeconds(int $expiresIn): int
    {
        return max(60, $expiresIn - self::AUTH_CACHE_TTL_BUFFER_SECONDS);
    }

    private function tokenUrl(string $baseUrl): string
    {
        return $this->normaliseSalesforceBaseUrl($baseUrl).'/services/oauth2/token';
    }

    private function jwtAudience(): string
    {
        $audience = (string) config('services.salesforce.jwt_audience', '');

        if (blank($audience)) {
            $audience = (string) config('services.salesforce.url', '');
        }

        return $this->normaliseSalesforceBaseUrl($audience);
    }

    private function normaliseSalesforceBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');

        if (str_contains($url, '/services/oauth2/token')) {
            $url = explode('/services/oauth2/token', $url)[0];
        }

        if (str_contains($url, '/services/data/')) {
            $url = explode('/services/data/', $url)[0];
        }

        $parsed = parse_url($url);

        return sprintf(
            '%s://%s',
            $parsed['scheme'] ?? 'https',
            $parsed['host'] ?? '',
        );
    }

    private function jwtPrivateKey(): ?string
    {
        $path = (string) config('services.salesforce.jwt_private_key_path', '');

        if (filled($path)) {
            $resolvedPath = str_starts_with($path, '/') ? $path : base_path($path);
            $contents = @file_get_contents($resolvedPath);

            if ($contents === false) {
                Log::error('Salesforce JWT private key file could not be read.', [
                    'path' => $resolvedPath,
                ]);

                return null;
            }

            return $contents;
        }

        $privateKey = (string) config('services.salesforce.jwt_private_key', '');

        if (blank($privateKey)) {
            return null;
        }

        $normalised = str_replace('\\n', "\n", $privateKey);

        if (str_contains($normalised, '-----BEGIN')) {
            return $normalised;
        }

        $decoded = base64_decode($privateKey, true);

        if (is_string($decoded) && str_contains($decoded, '-----BEGIN')) {
            return $decoded;
        }

        return $normalised;
    }

    private function jwtAssertion(string $clientId, string $subject, string $audience, string $privateKey): ?string
    {
        try {
            $encodedHeader = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR));
            $encodedPayload = $this->base64UrlEncode(json_encode([
                'iss' => $clientId,
                'sub' => $subject,
                'aud' => $audience,
                'exp' => now()->addMinutes(5)->timestamp,
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            Log::error('Salesforce JWT assertion could not be encoded.', [
                'exception' => $exception,
            ]);

            return null;
        }

        $signingInput = "{$encodedHeader}.{$encodedPayload}";
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            Log::error('Salesforce JWT private key could not be loaded.');

            return null;
        }

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            Log::error('Salesforce JWT assertion could not be signed.');

            return null;
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
     * Search Opportunities by project reference for typeahead Select.
     *
     * @return array<string, string> Keyed by Opportunity ID, value is display label.
     */
    public function searchOpportunitiesByReference(string $query, int $limit = 10): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return [];
        }

        $escaped = $this->soqlEscape($query);
        $where = $this->openOpportunityWhereClause("Project_Reference_Number__c LIKE '%{$escaped}%'");
        $result = $this->soqlQuery(
            $auth,
            "SELECT Id, Name, Project_Reference_Number__c FROM Opportunity{$where} ORDER BY Project_Reference_Number__c ASC LIMIT {$limit}",
        );

        $options = [];

        foreach ($result['records'] ?? [] as $record) {
            $reference = $record['Project_Reference_Number__c'] ?? '';
            $name = $record['Name'] ?? '';
            $options[$record['Id']] = filled($reference)
                ? "{$reference} — {$name}"
                : $name;
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
            "SELECT Id, Name, Project_Reference_Number__c, Miscellaneous_Customer_Name__c, CEF_Cover__c, Amount, OwnerId FROM Opportunity{$where} LIMIT 1",
        );

        $record = ($result['records'] ?? [])[0] ?? $this->getOpportunitySummaryByIdUsingAuth($auth, $id);

        if ($record === null) {
            return null;
        }

        $record = array_replace_recursive($record, $this->getOpportunityRelationshipFieldsByIdUsingAuth($auth, $id) ?? []);
        $branch = $this->getOpportunityBranchByIdUsingAuth($auth, $id);

        if ($branch !== null) {
            $record['CEF_Branch__r']['Name'] = $branch;
        }

        return $record;
    }

    /**
     * Fetch only the fields required to unblock project creation.
     *
     * @param  array{token: string, instanceUrl: string}  $auth
     * @return array<string, mixed>|null
     */
    private function getOpportunitySummaryByIdUsingAuth(array $auth, string $id): ?array
    {
        $escaped = $this->soqlEscape($id);
        $where = $this->openOpportunityWhereClause("Id = '{$escaped}'");
        $result = $this->soqlQuery(
            $auth,
            "SELECT Id, Name, Project_Reference_Number__c FROM Opportunity{$where} LIMIT 1",
        );

        return ($result['records'] ?? [])[0] ?? null;
    }

    /**
     * Relationship fields are useful, but Salesforce permissions can make them
     * unavailable even when the Opportunity itself is readable.
     *
     * @param  array{token: string, instanceUrl: string}  $auth
     * @return array<string, mixed>|null
     */
    private function getOpportunityRelationshipFieldsByIdUsingAuth(array $auth, string $id): ?array
    {
        $escaped = $this->soqlEscape($id);
        $where = $this->openOpportunityWhereClause("Id = '{$escaped}'");
        $result = $this->soqlQuery(
            $auth,
            "SELECT Id, Owner.Name, Owner.Email, Account.Name FROM Opportunity{$where} LIMIT 1",
        );

        return ($result['records'] ?? [])[0] ?? null;
    }

    public function getOpportunityBranch(string $opportunityId): ?string
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return null;
        }

        return $this->getOpportunityBranchByIdUsingAuth($auth, $opportunityId);
    }

    /**
     * @param  array{token: string, instanceUrl: string}  $auth
     */
    private function getOpportunityBranchByIdUsingAuth(array $auth, string $id): ?string
    {
        $escaped = $this->soqlEscape($id);
        $result = $this->soqlQuery(
            $auth,
            "SELECT Id, CEF_Branch__c, CEF_Branch__r.Name FROM Opportunity WHERE Id = '{$escaped}' LIMIT 1",
        );
        $branch = ($result['records'] ?? [])[0]['CEF_Branch__r']['Name'] ?? null;

        return filled($branch) ? (string) $branch : null;
    }

    public function updateOpportunityAmount(Project $project, float $amount): array
    {
        if (app(SalesforcePushControl::class)->disabled()) {
            return ['success' => false, 'message' => 'Salesforce pushes are currently paused.'];
        }

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
     * Upload a PDF as a Salesforce file version attached to the project's Opportunity.
     *
     * @return array{success: bool, url?: string, contentVersionId?: string, contentDocumentId?: string, message?: string}
     */
    public function uploadPdf(Project $project, string $pdfContent, string $filename): array
    {
        if (app(SalesforcePushControl::class)->disabled()) {
            return ['success' => false, 'message' => 'Salesforce pushes are currently paused.'];
        }

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

    /**
     * @return array{success: bool, url?: string, contentVersionId?: string, contentDocumentId?: string, message?: string}
     */
    public function uploadSchedulePdf(Project $project, string $pdfContent, string $filename): array
    {
        return $this->uploadPdf($project, $pdfContent, $filename);
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

    /**
     * Fetch the Account linked to an Opportunity.
     *
     * @return array<string, mixed>|null
     */
    public function getAccountForOpportunityId(string $opportunityId): ?array
    {
        $result = $this->fetchAccountForOpportunity($opportunityId);

        return $result['record'] ?? null;
    }

    /**
     * Fetch the Account linked to an Opportunity for CLI diagnostics.
     *
     * @return array{success: bool, opportunityId: string, accountId?: string, record?: array<string, mixed>, status?: int, errors?: mixed}
     */
    public function fetchAccountForOpportunity(string $opportunityId): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return [
                'success' => false,
                'opportunityId' => $opportunityId,
                'status' => 0,
                'errors' => ['Authentication failed'],
            ];
        }

        $accountId = $this->findAccountIdForOpportunityUsingAuth($auth, $opportunityId);

        if (blank($accountId)) {
            return [
                'success' => false,
                'opportunityId' => $opportunityId,
                'status' => 0,
                'errors' => ['No Account lookup was found on the Opportunity'],
            ];
        }

        $account = $this->fetchAllAccountFieldsUsingAuth($auth, $accountId);

        if ($account === null) {
            return [
                'success' => false,
                'opportunityId' => $opportunityId,
                'accountId' => $accountId,
                'status' => 0,
                'errors' => ['Account query failed'],
            ];
        }

        return [
            'success' => true,
            'opportunityId' => $opportunityId,
            'accountId' => $accountId,
            'record' => $account,
        ];
    }

    /**
     * Fetch all available fields for the Owner and Created By users linked to an Opportunity.
     *
     * @return array{success: bool, opportunityId: string, records?: array<int, array<string, mixed>>, status?: int, errors?: mixed}
     */
    public function fetchUsersForOpportunity(string $opportunityId): array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return [
                'success' => false,
                'opportunityId' => $opportunityId,
                'status' => 0,
                'errors' => ['Authentication failed'],
            ];
        }

        $escapedOpportunityId = $this->soqlEscape($opportunityId);
        $opportunityResult = $this->soqlQuery(
            $auth,
            "SELECT Id, OwnerId, CreatedById FROM Opportunity WHERE Id = '{$escapedOpportunityId}' LIMIT 1",
        );
        $opportunity = ($opportunityResult['records'] ?? [])[0] ?? null;

        if ($opportunity === null) {
            return [
                'success' => false,
                'opportunityId' => $opportunityId,
                'status' => 0,
                'errors' => ['Opportunity was not found or its User links could not be read'],
            ];
        }

        $relationshipsByUserId = collect([
            'Owner' => $opportunity['OwnerId'] ?? null,
            'CreatedBy' => $opportunity['CreatedById'] ?? null,
        ])
            ->filter(fn (mixed $userId): bool => filled($userId))
            ->groupBy(fn (mixed $userId): string => (string) $userId, preserveKeys: true)
            ->map(fn ($relationships): array => $relationships->keys()->all());

        if ($relationshipsByUserId->isEmpty()) {
            return [
                'success' => false,
                'opportunityId' => $opportunityId,
                'status' => 0,
                'errors' => ['No OwnerId or CreatedById was found on the Opportunity'],
            ];
        }

        $records = $this->fetchAllUserFieldsUsingAuth($auth, $relationshipsByUserId->keys()->all());

        if ($records === null) {
            return [
                'success' => false,
                'opportunityId' => $opportunityId,
                'status' => 0,
                'errors' => ['User describe or query failed'],
            ];
        }

        return [
            'success' => true,
            'opportunityId' => $opportunityId,
            'records' => collect($records)
                ->map(function (array $record) use ($relationshipsByUserId): array {
                    $record['_OpportunityRelationships'] = $relationshipsByUserId->get((string) ($record['Id'] ?? ''), []);

                    return $record;
                })
                ->all(),
        ];
    }

    /**
     * Fetch the name and email of the User referenced by Opportunity.OwnerId.
     *
     * @return array{id: string, name: string|null, email: string|null}|null
     */
    public function getOpportunityOwner(string $opportunityId): ?array
    {
        $auth = $this->authenticate();

        if ($auth === null) {
            return null;
        }

        $escapedOpportunityId = $this->soqlEscape($opportunityId);
        $opportunityResult = $this->soqlQuery(
            $auth,
            "SELECT Id, OwnerId FROM Opportunity WHERE Id = '{$escapedOpportunityId}' LIMIT 1",
        );
        $ownerId = ($opportunityResult['records'] ?? [])[0]['OwnerId'] ?? null;

        if (blank($ownerId)) {
            return null;
        }

        $escapedOwnerId = $this->soqlEscape((string) $ownerId);
        $userResult = $this->soqlQuery(
            $auth,
            "SELECT Id, Name, Email FROM User WHERE Id = '{$escapedOwnerId}' LIMIT 1",
        );
        $user = ($userResult['records'] ?? [])[0] ?? null;

        if ($user === null) {
            return null;
        }

        return [
            'id' => (string) ($user['Id'] ?? $ownerId),
            'name' => filled($user['Name'] ?? null) ? (string) $user['Name'] : null,
            'email' => filled($user['Email'] ?? null)
                ? str_replace('.invalid', '', (string) $user['Email'])
                : null,
        ];
    }

    /**
     * @param  array{token: string, instanceUrl: string}  $auth
     */
    private function findAccountIdForOpportunityUsingAuth(array $auth, string $opportunityId): ?string
    {
        $escapedOpportunityId = $this->soqlEscape($opportunityId);

        foreach ([
            ['AccountId', 'End_Client_ID__c'],
            ['AccountId'],
            ['End_Client_ID__c'],
        ] as $fields) {
            $result = $this->soqlQuery(
                $auth,
                'SELECT Id, '.implode(', ', $fields)." FROM Opportunity WHERE Id = '{$escapedOpportunityId}' LIMIT 1",
            );

            $record = ($result['records'] ?? [])[0] ?? null;

            if ($record === null) {
                continue;
            }

            foreach (['AccountId', 'End_Client_ID__c'] as $field) {
                if (filled($record[$field] ?? null)) {
                    return (string) $record[$field];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{token: string, instanceUrl: string}  $auth
     * @return array<string, mixed>|null
     */
    private function fetchAllAccountFieldsUsingAuth(array $auth, string $accountId): ?array
    {
        $describe = Http::withToken($auth['token'])
            ->acceptJson()
            ->get("{$auth['instanceUrl']}/services/data/".self::API_VERSION.'/sobjects/Account/describe');

        if ($describe->failed()) {
            Log::error('Salesforce Account describe failed', [
                'status' => $describe->status(),
                'body' => $describe->body(),
            ]);

            return null;
        }

        $fieldNames = array_column($describe->json()['fields'] ?? [], 'name');

        if (empty($fieldNames)) {
            return null;
        }

        $escapedAccountId = $this->soqlEscape($accountId);
        $result = $this->soqlQuery(
            $auth,
            'SELECT '.implode(', ', $fieldNames)." FROM Account WHERE Id = '{$escapedAccountId}' LIMIT 1",
        );

        return ($result['records'] ?? [])[0] ?? null;
    }

    /**
     * @param  array{token: string, instanceUrl: string}  $auth
     * @param  array<int, string>  $userIds
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchAllUserFieldsUsingAuth(array $auth, array $userIds): ?array
    {
        $describe = Http::withToken($auth['token'])
            ->acceptJson()
            ->get("{$auth['instanceUrl']}/services/data/".self::API_VERSION.'/sobjects/User/describe');

        if ($describe->failed()) {
            Log::error('Salesforce User describe failed', [
                'status' => $describe->status(),
                'body' => $describe->body(),
            ]);

            return null;
        }

        $fieldNames = array_column($describe->json()['fields'] ?? [], 'name');

        if (empty($fieldNames)) {
            return null;
        }

        $escapedUserIds = collect($userIds)
            ->map(fn (string $userId): string => "'{$this->soqlEscape($userId)}'")
            ->implode(', ');
        $result = $this->soqlQuery(
            $auth,
            'SELECT '.implode(', ', $fieldNames)." FROM User WHERE Id IN ({$escapedUserIds})",
        );

        return $result['records'] ?? null;
    }
}
