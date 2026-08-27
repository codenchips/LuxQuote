<?php

namespace Tests\Feature;

use App\Services\SalesforceService;
use Tests\TestCase;

class SalesforceCalendarCommandTest extends TestCase
{
    public function test_command_lists_available_calendars_as_json(): void
    {
        $this->app->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchPublicCalendars(int $limit = 100): array
            {
                return [
                    'success' => true,
                    'records' => [[
                        'Id' => '023000000000001AAA',
                        'Name' => 'Lighting Design',
                        'Type' => 'Public',
                    ]],
                ];
            }
        });

        $this->artisan('salesforce:calendars', ['--format' => 'json'])
            ->expectsOutputToContain('Lighting Design')
            ->assertSuccessful();
    }

    public function test_command_lists_bookings_for_a_calendar_and_date_range(): void
    {
        $fake = new class extends SalesforceService
        {
            /** @var array<string, mixed> */
            public array $arguments = [];

            public function fetchCalendarBookings(
                string $calendarId,
                string $from,
                ?string $to = null,
                int $limit = 100,
            ): array {
                $this->arguments = compact('calendarId', 'from', 'to', 'limit');

                return [
                    'success' => true,
                    'records' => [[
                        'Id' => '00U000000000001AAA',
                        'Subject' => 'Hospital survey',
                        'StartDateTime' => '2026-09-04T09:00:00.000+0000',
                        'EndDateTime' => '2026-09-04T10:00:00.000+0000',
                        'IsAllDayEvent' => false,
                        'Location' => 'Birmingham',
                        'OwnerId' => '023000000000001AAA',
                    ]],
                ];
            }
        };

        $this->app->instance(SalesforceService::class, $fake);

        $this->artisan('salesforce:calendars', [
            'calendar' => '023000000000001AAA',
            '--from' => '2026-09-01',
            '--to' => '2026-09-30',
            '--limit' => '25',
            '--format' => 'json',
        ])
            ->expectsOutputToContain('Hospital survey')
            ->assertSuccessful();

        $this->assertSame([
            'calendarId' => '023000000000001AAA',
            'from' => '2026-08-31T23:00:00Z',
            'to' => '2026-09-30T22:59:59Z',
            'limit' => 25,
        ], $fake->arguments);
    }

    public function test_command_rejects_an_unknown_output_format(): void
    {
        $this->artisan('salesforce:calendars', ['--format' => 'csv'])
            ->expectsOutput('Invalid format. Use table or json.')
            ->assertFailed();
    }
}
