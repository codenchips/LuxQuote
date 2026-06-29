<?php

namespace App\Console\Commands;

use App\Services\SalesforceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature('salesforce:interrogate {--limit=5 : Number of Opportunity records to fetch} {--format=table : Output format: table, json, or ndjson}')]
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
