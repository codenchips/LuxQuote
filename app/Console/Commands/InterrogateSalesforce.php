<?php

namespace App\Console\Commands;

use App\Services\SalesforceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature('salesforce:interrogate {--limit=5 : Number of Opportunity records to fetch} {--format=table : Output format: table, json, or ndjson} {--account-for-opportunity= : Fetch all fields for the Account linked to this Opportunity ID} {--users-for-opportunity= : Fetch all fields for the Owner and Created By users linked to this Opportunity ID}')]
#[Description('Fetch raw project/opportunity records from the Salesforce API and print them to the terminal.')]
class InterrogateSalesforce extends Command
{
    public function __construct(private readonly SalesforceService $salesforce)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        $limit = max(1, (int) $this->option('limit'));

        if (! in_array($format, ['table', 'json', 'ndjson'], true)) {
            $this->error('Invalid format. Use table, json, or ndjson.');

            return self::FAILURE;
        }

        if ($format === 'table') {
            $this->info('Contacting Salesforce API...');
        }

        $accountOpportunityId = $this->option('account-for-opportunity');

        if (filled($accountOpportunityId)) {
            return $this->writeAccountForOpportunity((string) $accountOpportunityId, $format);
        }

        $usersOpportunityId = $this->option('users-for-opportunity');

        if (filled($usersOpportunityId)) {
            return $this->writeUsersForOpportunity((string) $usersOpportunityId, $format);
        }

        $response = $this->salesforce->fetchAllOpportunityFields($limit);

        if (isset($response['success']) && ! $response['success']) {
            $this->error(sprintf('Salesforce API Error (HTTP %d)', $response['status']));

            if (! empty($response['errors'])) {
                foreach ($response['errors'] as $error) {
                    $this->line(sprintf(
                        '  Code: <comment>%s</comment> - Message: <fg=red>%s</>',
                        $error['errorCode'] ?? 'UNKNOWN',
                        $error['message'] ?? 'No message provided.'
                    ));
                }
            }

            return self::FAILURE;
        }

        $records = $response['records'] ?? [];
        if (empty($records)) {
            $this->warn('Connected successfully, but the Opportunity (Project) table returned 0 rows.');

            return self::SUCCESS;
        }

        if ($format !== 'table') {
            return $this->writeStructuredRecords($records, $format);
        }

        $this->info(sprintf('Received %d record(s).', count($records)));

        $firstRecord = reset($records);

        if (is_array($firstRecord)) {
            $this->line('');
            $this->info('Keys available on each record:');
            $this->line(implode(', ', array_keys($firstRecord)));
            $this->line('');

            $headers = array_keys($firstRecord);
            $rows = array_map(
                fn (array $record): array => array_map(
                    fn (mixed $v): string => is_array($v) ? json_encode($v) : (string) $v,
                    $record,
                ),
                $records,
            );

            $this->table($headers, $rows);
        } else {
            // Simple scalar list.
            foreach (array_slice($records, 0, 25) as $record) {
                $this->line(json_encode($record));
            }
        }

        if (count($records) > 25) {
            $this->warn(sprintf('(Showing first 25 of %d records)', count($records)));
        }

        return self::SUCCESS;
    }

    private function writeAccountForOpportunity(string $opportunityId, string $format): int
    {
        $response = $this->salesforce->fetchAccountForOpportunity($opportunityId);

        if (! ($response['success'] ?? false)) {
            $this->error(sprintf('Salesforce Account lookup failed for Opportunity %s', $opportunityId));

            if (! empty($response['errors'])) {
                foreach ((array) $response['errors'] as $error) {
                    $this->line(is_array($error) ? json_encode($error) : (string) $error);
                }
            }

            return self::FAILURE;
        }

        $record = $response['record'] ?? [];

        if ($format !== 'table') {
            return $this->writeStructuredRecords([$record], $format);
        }

        $this->info(sprintf(
            'Received Account %s linked to Opportunity %s.',
            $response['accountId'] ?? 'unknown',
            $opportunityId,
        ));
        $this->line('');
        $this->info('Keys available on the Account record:');
        $this->line(implode(', ', array_keys($record)));
        $this->line('');

        $this->table(
            array_keys($record),
            [
                array_map(
                    fn (mixed $value): string => is_array($value) ? json_encode($value) : (string) $value,
                    $record,
                ),
            ],
        );

        return self::SUCCESS;
    }

    private function writeUsersForOpportunity(string $opportunityId, string $format): int
    {
        $response = $this->salesforce->fetchUsersForOpportunity($opportunityId);

        if (! ($response['success'] ?? false)) {
            $this->error(sprintf('Salesforce User lookup failed for Opportunity %s', $opportunityId));

            if (! empty($response['errors'])) {
                foreach ((array) $response['errors'] as $error) {
                    $this->line(is_array($error) ? json_encode($error) : (string) $error);
                }
            }

            return self::FAILURE;
        }

        $records = $response['records'] ?? [];

        if ($format !== 'table') {
            return $this->writeStructuredRecords($records, $format);
        }

        $this->info(sprintf(
            'Received %d User record(s) linked to Opportunity %s.',
            count($records),
            $opportunityId,
        ));

        foreach ($records as $record) {
            $relationships = implode(', ', $record['_OpportunityRelationships'] ?? []);
            $this->line('');
            $this->info(sprintf('%s: %s', $relationships ?: 'Linked User', $record['Name'] ?? $record['Id'] ?? 'unknown'));
            $this->table(
                ['Field', 'Value'],
                collect($record)
                    ->map(fn (mixed $value, string $field): array => [
                        $field,
                        is_array($value) ? json_encode($value) : (string) $value,
                    ])
                    ->values()
                    ->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $records
     */
    private function writeStructuredRecords(array $records, string $format): int
    {
        try {
            if ($format === 'json') {
                $this->line(json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            foreach ($records as $record) {
                $this->line(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        } catch (JsonException $exception) {
            $this->error('Could not encode Salesforce records: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
