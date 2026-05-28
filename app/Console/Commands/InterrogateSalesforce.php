<?php

namespace App\Console\Commands;

use App\Services\SalesforceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('salesforce:interrogate')]
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
        $this->info('Contacting Salesforce API...');

        // $response = $this->salesforce->fetchProjects();
        $response = $this->salesforce->fetchAllOpportunityFields(25);

        // 1. Handle API/Permission Failures explicitly
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

        // 2. Handle successful connections with an empty database
        $records = $response['records'] ?? [];
        if (empty($records)) {
            $this->warn('Connected successfully, but the Opportunity (Project) table returned 0 rows.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Received %d record(s).', count($records)));

        // Print the top-level keys from the first record so we know what fields are available.
        $firstRecord = reset($records);

        if (is_array($firstRecord)) {
            $this->line('');
            $this->info('Keys available on each record:');
            $this->line(implode(', ', array_keys($firstRecord)));
            $this->line('');

            // Render first 25 records in a table using those keys.
            $headers = array_keys($firstRecord);
            $rows = array_map(
                fn (array $record): array => array_map(
                    fn (mixed $v): string => is_array($v) ? json_encode($v) : (string) $v,
                    $record,
                ),
                array_slice($records, 0, 25),
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
}
