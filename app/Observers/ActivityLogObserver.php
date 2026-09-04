<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Services\ReportingEventService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ActivityLogObserver
{
    /**
     * Handle the ActivityLog "created" event.
     */
    public function created(ActivityLog $activityLog): void
    {
        if (! Schema::hasTable('reporting_events')) {
            return;
        }

        try {
            app(ReportingEventService::class)->capture($activityLog);
        } catch (Throwable $exception) {
            Log::warning('A reporting event could not be captured and will be retried by reconciliation.', [
                'activity_log_id' => $activityLog->id,
                'action_type' => $activityLog->action_type,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
