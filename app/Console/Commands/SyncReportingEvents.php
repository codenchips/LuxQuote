<?php

namespace App\Console\Commands;

use App\Services\ReportingEventService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-reporting-events')]
#[Description('Capture any retained activity records missing from durable management reporting.')]
class SyncReportingEvents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReportingEventService $reportingEvents): int
    {
        $count = $reportingEvents->syncMissing();
        $this->info("Captured {$count} reporting event(s).");

        return self::SUCCESS;
    }
}
