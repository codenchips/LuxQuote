<?php

namespace App\Console\Commands;

use App\Services\SalesforceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature('salesforce:sample-object {object : Salesforce object API name, e.g. Account, User, Opportunity} {limit=1 : Number of records to return, max 25} {--format=json : Output format: json, ndjson, or table}')]
#[Description('Fetch sample Salesforce object records with every readable field.')]
class SampleSalesforceObject extends Command
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
        $object = (string) $this->argument('object');
        $limit = max(1, min(25, (int) $this->argument('limit')));
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['json', 'ndjson', 'table'], true)) {
            $this->error('Invalid format. Use json, ndjson, or table.');

            return self::FAILURE;
        }

        $response = $this->salesforce->fetchObjectSampleFields($object, $limit);

        if (! ($response['success'] ?? false)) {
            $this->error(sprintf('Salesforce %s sample failed.', $object));

            foreach ((array) ($response['errors'] ?? []) as $error) {
                $this->line(is_array($error) ? json_encode($error) : (string) $error);
            }

            return self::FAILURE;
        }

        $records = $response['records'] ?? [];
        $skippedFields = $response['skipped_fields'] ?? [];

        if ($format === 'table') {
            return $this->writeTable($object, $records, $skippedFields);
        }

        return $this->writeStructured($object, $records, $skippedFields, $format);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $skippedFields
     */
    private function writeTable(string $object, array $records, array $skippedFields): int
    {
        $this->info(sprintf('Received %d %s record(s).', count($records), $object));

        if ($skippedFields !== []) {
            $this->warn('Skipped fields: '.implode(', ', $skippedFields));
        }

        foreach ($records as $record) {
            $this->line('');
            $this->info((string) ($record['Name'] ?? $record['Username'] ?? $record['Id'] ?? $object));
            $this->table(
                ['Field', 'Value'],
                collect($record)
                    ->reject(fn (mixed $value, string $field): bool => $field === 'attributes')
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
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $skippedFields
     */
    private function writeStructured(string $object, array $records, array $skippedFields, string $format): int
    {
        try {
            if ($format === 'json') {
                $this->line(json_encode([
                    'object' => $object,
                    'records' => $records,
                    'skipped_fields' => $skippedFields,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            foreach ($records as $record) {
                $this->line(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            if ($skippedFields !== []) {
                $this->line(json_encode([
                    'object' => $object,
                    'skipped_fields' => $skippedFields,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        } catch (JsonException $exception) {
            $this->error('Could not encode Salesforce records: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
