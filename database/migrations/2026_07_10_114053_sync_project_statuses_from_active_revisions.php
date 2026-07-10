<?php

use App\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('projects')
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('project_revisions')
                    ->whereColumn('project_revisions.id', 'projects.active_revision_id')
                    ->whereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('activity_logs')
                            ->whereColumn('activity_logs.project_id', 'projects.id')
                            ->whereColumn('activity_logs.revision_number', 'project_revisions.revision_number')
                            ->where(function ($query): void {
                                $query->where('activity_logs.action_type', 'quote_pdf.generated')
                                    ->orWhere(function ($query): void {
                                        $query->where('activity_logs.action_type', 'document_pack.generated')
                                            ->where('activity_logs.payload->contains_quote', true);
                                    });
                            });
                    });
            })
            ->update(['status' => ProjectStatus::Quoted->value]);

        DB::table('projects')
            ->whereNotIn('status', [
                ProjectStatus::Archived->value,
                ProjectStatus::Quoted->value,
            ])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('project_revisions')
                    ->whereColumn('project_revisions.id', 'projects.active_revision_id')
                    ->where('project_revisions.status', 'approved');
            })
            ->update(['status' => ProjectStatus::Approved->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
