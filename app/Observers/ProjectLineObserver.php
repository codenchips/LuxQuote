<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\ProjectLine;

class ProjectLineObserver
{
    /** Stash pending update payloads between updating() and updated(). */
    private static array $pendingPayloads = [];

    /** Minutes after line creation during which field edits are folded into the product.added entry. */
    private const CREATION_WINDOW_MINUTES = 5;

    public function created(ProjectLine $line): void
    {
        $area = $line->area;
        $project = $area?->project;

        // Skip logging for lines copied during revision cloning — they are not new user decisions.
        // Only log when a line is added directly to the active revision.
        if (! $project || $area->project_revision_id !== $project->active_revision_id) {
            return;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $project->id,
            'action_type' => 'product.added',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $project->name,
            'payload' => [
                'line_id' => $line->id,
                'code' => $line->code,
                'description' => $line->description,
                'qty' => $line->qty,
            ],
        ]);
    }

    public function updating(ProjectLine $line): void
    {
        $trackedFields = ['code', 'ref', 'description', 'qty', 'unit_price', 'notes', 'type', 'status', 'validation_flagged', 'validation_note'];

        $changes = [];
        foreach ($trackedFields as $field) {
            if ($line->isDirty($field)) {
                $changes[$field] = ['old' => $line->getOriginal($field), 'new' => $line->$field];
            }
        }

        if (! empty($changes)) {
            self::$pendingPayloads[$line->id] = [
                'code' => $line->getOriginal('code') ?? $line->code,
                'changes' => $changes,
            ];
        }
    }

    public function updated(ProjectLine $line): void
    {
        $payload = self::$pendingPayloads[$line->id] ?? null;

        if (! $payload) {
            return;
        }

        $project = $line->area?->project;

        // If the line was created very recently, fold field edits into the original
        // product.added entry so the history shows one clean "Added..." record instead
        // of a blank addition followed by individual per-field update entries.
        $recentAddedLog = ActivityLog::where('project_id', $project?->id)
            ->where('action_type', 'product.added')
            ->where('created_at', '>=', now()->subMinutes(self::CREATION_WINDOW_MINUTES))
            ->where('payload->line_id', $line->id)
            ->latest('created_at')
            ->first();

        if ($recentAddedLog) {
            $updatedPayload = $recentAddedLog->payload ?? [];
            foreach ($payload['changes'] as $field => $change) {
                $updatedPayload[$field] = $change['new'];
            }
            $recentAddedLog->update(['payload' => $updatedPayload]);
        } else {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project?->id,
                'action_type' => 'line.updated',
                'user_email_snapshot' => auth()->user()?->email ?? '',
                'project_name_snapshot' => $project?->name ?? '',
                'payload' => $payload,
            ]);
        }

        unset(self::$pendingPayloads[$line->id]);
    }

    public function saved(ProjectLine $line): void
    {
        $this->touchProject($line);
    }

    public function deleted(ProjectLine $line): void
    {
        $this->touchProject($line);
    }

    private function touchProject(ProjectLine $line): void
    {
        $area = $line->area;

        if (! $area) {
            return;
        }

        $project = $area->project;

        if ($project && $area->project_revision_id === $project->active_revision_id) {
            $project->updateQuietly(['last_edited_at' => now(), 'last_edited_by' => auth()->id()]);
            $project->syncStatusFromActiveRevision();
        }
    }
}
