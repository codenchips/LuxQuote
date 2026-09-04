<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

#[Signature('app:prune-activity-logs {--months= : Override the configured retention period} {--chunk=500 : Number of rows to process per batch} {--dry-run : Report affected rows without changing them}')]
#[Description('Permanently delete activity history older than its retention period.')]
class PruneActivityLogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $months = $this->retentionMonths();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        $chunkSize = max(1, min(2000, (int) $this->option('chunk')));
        $cutoff = now()->subMonthsNoOverflow($months);
        $hasLegacyArchive = Schema::hasTable('activity_log_archives');

        $expiredLiveCount = DB::table('activity_logs')->where('created_at', '<', $cutoff)->count();
        $recentArchiveCount = $hasLegacyArchive
            ? DB::table('activity_log_archives')->where('created_at', '>=', $cutoff)->count()
            : 0;
        $expiredArchiveCount = $hasLegacyArchive
            ? DB::table('activity_log_archives')->where('created_at', '<', $cutoff)->count()
            : 0;

        if ($this->option('dry-run')) {
            $this->components->info(
                "Would restore {$recentArchiveCount} retained legacy archive row(s) and permanently delete "
                .($expiredLiveCount + $expiredArchiveCount)
                ." activity log row(s) older than {$months} month(s) ({$cutoff->toDateTimeString()})."
            );

            return self::SUCCESS;
        }

        try {
            $restoredCount = $hasLegacyArchive
                ? $this->restoreRetainedArchiveRows($cutoff, $chunkSize)
                : 0;
            $deletedLiveCount = $this->deleteExpiredRows('activity_logs', $cutoff, $chunkSize);
            $deletedArchiveCount = $hasLegacyArchive
                ? $this->deleteExpiredRows('activity_log_archives', $cutoff, $chunkSize)
                : 0;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Activity history pruning failed. The current batch was rolled back; rerunning is safe: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "Restored {$restoredCount} retained legacy archive row(s) and permanently deleted "
            .($deletedLiveCount + $deletedArchiveCount)
            ." activity log row(s) older than {$months} month(s)."
        );

        return self::SUCCESS;
    }

    private function retentionMonths(): int
    {
        $configuredMonths = max(1, (int) config('activity-log.retention_months', 3));
        $override = $this->option('months');

        if ($override === null || $override === '') {
            return $configuredMonths;
        }

        $validatedOverride = filter_var(
            $override,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($validatedOverride === false) {
            throw new InvalidArgumentException('The --months option must be a whole number of at least 1.');
        }

        return $validatedOverride;
    }

    private function restoreRetainedArchiveRows(\DateTimeInterface $cutoff, int $chunkSize): int
    {
        $restoredCount = 0;

        DB::table('activity_log_archives')
            ->where('created_at', '>=', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $archives) use (&$restoredCount): void {
                DB::transaction(function () use ($archives, &$restoredCount): void {
                    $validUserIds = DB::table('users')
                        ->whereIn('id', $archives->pluck('user_id')->filter()->unique())
                        ->pluck('id')
                        ->mapWithKeys(fn (int|string $id): array => [(int) $id => true]);
                    $validProjectIds = DB::table('projects')
                        ->whereIn('id', $archives->pluck('project_id')->filter()->unique())
                        ->pluck('id')
                        ->mapWithKeys(fn (int|string $id): array => [(int) $id => true]);

                    $rows = $archives->map(fn (object $archive): array => [
                        'user_id' => $archive->user_id !== null && $validUserIds->has((int) $archive->user_id)
                            ? (int) $archive->user_id
                            : null,
                        'project_id' => $archive->project_id !== null && $validProjectIds->has((int) $archive->project_id)
                            ? (int) $archive->project_id
                            : null,
                        'action_type' => $archive->action_type,
                        'user_email_snapshot' => $archive->user_email_snapshot,
                        'project_name_snapshot' => $archive->project_name_snapshot,
                        'revision_number' => $archive->revision_number,
                        'payload' => $archive->payload,
                        'created_at' => $archive->created_at,
                    ])->all();

                    DB::table('activity_logs')->insert($rows);
                    DB::table('activity_log_archives')->whereIn('id', $archives->pluck('id'))->delete();
                    $restoredCount += count($rows);
                });
            });

        return $restoredCount;
    }

    private function deleteExpiredRows(string $table, \DateTimeInterface $cutoff, int $chunkSize): int
    {
        $deletedCount = 0;

        do {
            $ids = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deletedCount += DB::transaction(
                fn (): int => DB::table($table)->whereIn('id', $ids)->delete()
            );
        } while ($ids->count() === $chunkSize);

        return $deletedCount;
    }
}
