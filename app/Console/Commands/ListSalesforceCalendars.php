<?php

namespace App\Console\Commands;

use App\Services\SalesforceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

#[Signature('salesforce:calendars {calendar? : Salesforce public Calendar ID; omit to list public calendars} {--from= : Booking start date or datetime; defaults to today} {--to= : Optional booking end date or datetime} {--limit=100 : Maximum records to return, max 200} {--format=table : Output format: table or json}')]
#[Description('List Salesforce public calendars or bookings for a particular public calendar.')]
class ListSalesforceCalendars extends Command
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

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Invalid format. Use table or json.');

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $calendarId = $this->argument('calendar');

        if ($calendarId === null) {
            return $this->listCalendars($limit, $format);
        }

        return $this->listBookings((string) $calendarId, $limit, $format);
    }

    private function listCalendars(int $limit, string $format): int
    {
        $response = $this->salesforce->fetchPublicCalendars($limit);

        if (! ($response['success'] ?? false)) {
            return $this->writeErrors('Could not fetch Salesforce public calendars.', $response);
        }

        $calendars = $response['records'] ?? [];

        if ($format === 'json') {
            return $this->writeJson(['calendars' => $calendars]);
        }

        $this->table(
            ['ID', 'Name', 'Type'],
            array_map(fn (array $calendar): array => [
                (string) ($calendar['Id'] ?? ''),
                (string) ($calendar['Name'] ?? ''),
                (string) ($calendar['Type'] ?? ''),
            ], $calendars),
        );

        return self::SUCCESS;
    }

    private function listBookings(string $calendarId, int $limit, string $format): int
    {
        try {
            $from = $this->salesforceDateTime((string) ($this->option('from') ?: 'today'));
            $toOption = $this->option('to');
            $to = $toOption === null ? null : $this->salesforceDateTime((string) $toOption, true);
        } catch (Throwable $exception) {
            $this->error('Invalid date: '.$exception->getMessage());

            return self::FAILURE;
        }

        $response = $this->salesforce->fetchCalendarBookings($calendarId, $from, $to, $limit);

        if (! ($response['success'] ?? false)) {
            return $this->writeErrors('Could not fetch Salesforce calendar bookings.', $response);
        }

        $bookings = $response['records'] ?? [];

        if ($format === 'json') {
            return $this->writeJson([
                'calendar_id' => $calendarId,
                'from' => $from,
                'to' => $to,
                'bookings' => $bookings,
            ]);
        }

        $this->table(
            ['ID', 'Subject', 'Start', 'End', 'All day', 'Location'],
            array_map(fn (array $booking): array => [
                (string) ($booking['Id'] ?? ''),
                (string) ($booking['Subject'] ?? ''),
                (string) ($booking['StartDateTime'] ?? ''),
                (string) ($booking['EndDateTime'] ?? ''),
                ($booking['IsAllDayEvent'] ?? false) ? 'Yes' : 'No',
                (string) ($booking['Location'] ?? ''),
            ], $bookings),
        );

        return self::SUCCESS;
    }

    private function salesforceDateTime(string $value, bool $endOfDayForDate = false): string
    {
        $dateTime = CarbonImmutable::parse($value, (string) config('app.timezone'));

        if ($endOfDayForDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $dateTime = $dateTime->endOfDay();
        }

        return $dateTime->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function writeErrors(string $message, array $response): int
    {
        $this->error($message);

        foreach ((array) ($response['errors'] ?? []) as $error) {
            $this->line(is_array($error) ? json_encode($error) : (string) $error);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): int
    {
        try {
            $this->line(json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException $exception) {
            $this->error('Could not encode Salesforce records: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
