<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ActivityLogArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('app:archive-activity-logs {--weeks=6 : Keep this many weeks of live logs} {--chunk=500 : Number of logs to process per batch} {--dry-run : Count eligible logs without moving them}')]
#[Description('Move old activity logs into the archive table.')]
class ArchiveActivityLogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $weeks = max(1, (int) $this->option('weeks'));
        $chunkSize = max(1, min(2000, (int) $this->option('chunk')));
        $cutoff = now()->subWeeks($weeks);
        $eligibleCount = ActivityLog::query()
            ->where('created_at', '<', $cutoff)
            ->count();

        if ($this->option('dry-run')) {
            $this->components->info("{$eligibleCount} activity log entries are older than {$weeks} weeks.");

            return self::SUCCESS;
        }

        if ($eligibleCount === 0) {
            $this->components->info("No activity log entries older than {$weeks} weeks were found.");

            return self::SUCCESS;
        }

        $archivedCount = 0;

        ActivityLog::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $logs) use (&$archivedCount): void {
                DB::transaction(function () use ($logs, &$archivedCount): void {
                    $now = now();
                    $rows = $logs
                        ->map(fn (ActivityLog $log): array => [
                            'original_activity_log_id' => $log->id,
                            'user_id' => $log->user_id,
                            'project_id' => $log->project_id,
                            'action_type' => $log->action_type,
                            'user_email_snapshot' => $log->user_email_snapshot,
                            'project_name_snapshot' => $log->project_name_snapshot,
                            'revision_number' => $log->revision_number,
                            'payload' => $log->payload !== null ? json_encode($log->payload, JSON_THROW_ON_ERROR) : null,
                            'created_at' => $log->created_at,
                            'archived_at' => $now,
                        ])
                        ->all();

                    ActivityLogArchive::query()->insertOrIgnore($rows);

                    $archivedIds = ActivityLogArchive::query()
                        ->whereIn('original_activity_log_id', $logs->pluck('id'))
                        ->pluck('original_activity_log_id');

                    ActivityLog::query()
                        ->whereIn('id', $archivedIds)
                        ->delete();

                    $archivedCount += $archivedIds->count();
                });
            });

        $this->components->info("Archived {$archivedCount} activity log entries older than {$weeks} weeks.");

        return self::SUCCESS;
    }
}
