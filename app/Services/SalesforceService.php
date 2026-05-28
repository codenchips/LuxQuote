<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SalesforceService
{
    /**
     * Obtain a Bearer token via the OAuth2 Client Credentials grant.
     */
    private function getAccessToken(): ?string
    {

        $baseUrl = config('services.salesforce.url', '');

        $parsed = parse_url((string) $baseUrl);
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

        // ADD THESE TWO TEMPORARY DIAGNOSTIC LINES HERE:
        logger('Salesforce Auth Status: '.$response->status());
        dd($response->json());

        if ($response->failed()) {
            Log::error('Salesforce getAccessToken failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }

    private function getAccessTokenPayload(): ?array
    {
        $response = Http::asForm()->post('https://test.salesforce.com/services/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.salesforce.client_id'),
            'client_secret' => config('services.salesforce.client_secret'),
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Fetch Opportunity records from the Salesforce REST API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProjects(): array
    {
        // 1. Get base domain
        $baseUrl = rtrim(config('services.salesforce.url'), '/');
        if (str_contains($baseUrl, '/services/data/')) {
            $baseUrl = explode('/services/data/', $baseUrl)[0];
        }

        // 2. Authenticate
        $authResponse = Http::asForm()->post("{$baseUrl}/services/oauth2/token", [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.salesforce.client_id'),
            'client_secret' => config('services.salesforce.client_secret'),
        ]);

        $authData = $authResponse->json();
        $token = $authData['access_token'];
        $instanceUrl = rtrim($authData['instance_url'], '/');

        // 3. Make the query to the Opportunity table
        $query = 'SELECT Id, Name, StageName, CloseDate FROM Opportunity LIMIT 25';

        // Temporarily query the User table to prove data visibility
        // $query = "SELECT Id, Name, Email FROM User LIMIT 5";

        $dataResponse = Http::withToken($token)
            ->acceptJson()
            ->get("{$instanceUrl}/services/data/v65.0/query/", [
                'q' => $query,
            ]);

        if ($dataResponse->successful()) {
            return [
                'success' => true,
                'records' => $dataResponse->json()['records'] ?? [],
            ];
        }

        // Capture the exact breakdown from Salesforce instead of hiding it
        return [
            'success' => false,
            'status' => $dataResponse->status(),
            'errors' => $dataResponse->json(),
        ];
    }

    /**
     * Dynamically fetch all available fields for Opportunity records.
     */
    public function fetchAllOpportunityFields(int $limit = 25): array
    {
        // 1. Get base domain and authenticate
        $baseUrl = rtrim(config('services.salesforce.url'), '/');
        if (str_contains($baseUrl, '/services/data/')) {
            $baseUrl = explode('/services/data/', $baseUrl)[0];
        }

        $authResponse = Http::asForm()->post("{$baseUrl}/services/oauth2/token", [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.salesforce.client_id'),
            'client_secret' => config('services.salesforce.client_secret'),
        ]);

        if ($authResponse->failed()) {
            logger()->error('SF Auth failed during field discovery.');

            return [];
        }

        $authData = $authResponse->json();
        $token = $authData['access_token'];
        $instanceUrl = rtrim($authData['instance_url'], '/');

        // 2. Describe the object to find every single existing field name
        $describeResponse = Http::withToken($token)
            ->acceptJson()
            ->get("{$instanceUrl}/services/data/v60.0/sobjects/Opportunity/describe");

        if ($describeResponse->failed()) {
            logger()->error('Failed to retrieve Opportunity object metadata description.');

            return [];
        }

        $metadata = $describeResponse->json();

        // Extract just the technical 'name' property from the field definitions
        $fieldNames = array_column($metadata['fields'] ?? [], 'name');

        if (empty($fieldNames)) {
            return [];
        }

        // 3. Construct a clean, comma-separated SOQL query string
        $commaSeparatedFields = implode(', ', $fieldNames);
        $query = "SELECT {$commaSeparatedFields} FROM Opportunity LIMIT {$limit}";

        // 4. Run the dynamic query
        $dataResponse = Http::withToken($token)
            ->acceptJson()
            ->get("{$instanceUrl}/services/data/v60.0/query/", [
                'q' => $query,
            ]);

        if ($dataResponse->successful()) {
            return [
                'success' => true,
                'records' => $dataResponse->json()['records'] ?? [],
            ];
        }

        return [
            'success' => false,
            'status' => $dataResponse->status(),
            'errors' => $dataResponse->json(),
        ];
    }
}
