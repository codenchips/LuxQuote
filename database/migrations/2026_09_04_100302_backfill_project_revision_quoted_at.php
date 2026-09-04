<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('project_revisions', 'quoted_at')) {
            return;
        }

        $this->backfillQuotedAtFromProjectStatus();

        if (Schema::hasTable('activity_logs')) {
            $this->backfillQuotedAtFrom('activity_logs');
        }

        if (Schema::hasTable('activity_log_archives')) {
            $this->backfillQuotedAtFrom('activity_log_archives');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration only backfills durable state and is intentionally not reversed.
    }

    private function backfillQuotedAtFrom(string $table): void
    {
        DB::table($table)
            ->whereNotNull('project_id')
            ->whereIn('action_type', ['quote_pdf.generated', 'document_pack.generated'])
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                foreach ($logs as $log) {
                    $payload = json_decode((string) ($log->payload ?? ''), true);
                    $containsQuote = $log->action_type === 'quote_pdf.generated'
                        || ($log->action_type === 'document_pack.generated' && ($payload['contains_quote'] ?? false) === true);

                    if (! $containsQuote) {
                        continue;
                    }

                    $revisionNumber = $log->revision_number ?? ($payload['revision_number'] ?? null);

                    if (! is_numeric($revisionNumber)) {
                        continue;
                    }

                    DB::table('project_revisions')
                        ->where('project_id', $log->project_id)
                        ->where('revision_number', (int) $revisionNumber)
                        ->where(function ($query) use ($log): void {
                            $query->whereNull('quoted_at')
                                ->orWhere('quoted_at', '>', $log->created_at);
                        })
                        ->update(['quoted_at' => $log->created_at]);
                }
            });
    }

    private function backfillQuotedAtFromProjectStatus(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }

        DB::table('projects')
            ->where('status', 'quoted')
            ->whereNotNull('active_revision_id')
            ->orderBy('id')
            ->chunkById(500, function ($projects): void {
                foreach ($projects as $project) {
                    DB::table('project_revisions')
                        ->where('id', $project->active_revision_id)
                        ->where('project_id', $project->id)
                        ->whereNull('quoted_at')
                        ->update([
                            'quoted_at' => $project->updated_at ?? $project->created_at ?? now(),
                        ]);
                }
            });
    }
};
