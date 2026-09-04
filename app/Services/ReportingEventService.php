<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Models\ReportingEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportingEventService
{
    /** @var array<string, string> */
    private const EventTypes = [
        'user.login' => 'login',
        'project.created' => 'project_created',
        'schedule_pdf.generated' => 'schedule',
        'quote_pdf.generated' => 'quote',
        'document_pack.generated' => 'document_pack',
    ];

    public function capture(ActivityLog $activityLog): ?ReportingEvent
    {
        $eventType = self::EventTypes[$activityLog->action_type] ?? null;

        if ($eventType === null) {
            return null;
        }

        $activityLog->loadMissing(['user', 'project.tenders']);
        $project = $activityLog->project;
        $payload = is_array($activityLog->payload) ? $activityLog->payload : [];
        $batchKey = $this->batchKey($activityLog, $payload);
        $revision = $this->revision($project, $activityLog, $payload);
        $lines = $this->lines($revision, $payload);
        [$netValue, $grossValue] = $this->values($project, $lines);
        $owner = $this->owner($project);

        return DB::transaction(function () use ($activityLog, $eventType, $project, $payload, $batchKey, $revision, $lines, $netValue, $grossValue, $owner): ReportingEvent {
            $event = ReportingEvent::query()->updateOrCreate(
                ['activity_log_id' => $activityLog->id],
                [
                    'event_type' => $eventType,
                    'generation_batch_key' => $batchKey,
                    'occurred_at' => $activityLog->created_at ?? now(),
                    'user_id' => $activityLog->user_id,
                    'user_name_snapshot' => $activityLog->user?->name,
                    'user_email_snapshot' => $activityLog->user_email_snapshot,
                    'project_id' => $project?->id,
                    'project_reference_snapshot' => $project?->reference_number,
                    'project_name_snapshot' => $activityLog->project_name_snapshot ?: $project?->name,
                    'owner_name_snapshot' => $project?->owner_name ?: $owner?->name,
                    'owner_email_snapshot' => $project?->owner_email,
                    'revision_number' => $revision?->revision_number ?? $activityLog->revision_number,
                    'currency' => $project?->currency ? strtoupper((string) $project->currency) : null,
                    'net_value' => $netValue,
                    'gross_value' => $grossValue,
                    'has_cover' => $project?->has_cover,
                    'effective_cover_percentage' => $this->effectiveCover($netValue, $grossValue),
                    'include_datasheets' => array_key_exists('include_datasheets', $payload) ? (bool) $payload['include_datasheets'] : null,
                    'include_cover_letter' => array_key_exists('include_cover', $payload) ? (bool) $payload['include_cover'] : null,
                    'include_legal_page' => array_key_exists('include_legal_page', $payload) ? (bool) $payload['include_legal_page'] : null,
                    'tender_count' => $eventType === 'quote' ? $this->tenderCount($payload) : null,
                    'document_count' => $eventType === 'document_pack' ? $this->documentCount($payload) : null,
                    'metadata' => $this->metadata($project, $payload),
                ],
            );

            if ($eventType === 'quote' && $this->isFirstQuoteInBatch($event)) {
                $this->syncProducts($event, $lines);
            }

            return $event;
        });
    }

    public function syncMissing(int $chunkSize = 200): int
    {
        $captured = 0;

        ActivityLog::query()
            ->whereIn('action_type', array_keys(self::EventTypes))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('reporting_events')
                    ->whereColumn('reporting_events.activity_log_id', 'activity_logs.id');
            })
            ->oldest('id')
            ->chunkById($chunkSize, function (Collection $logs) use (&$captured): void {
                foreach ($logs as $log) {
                    if ($this->capture($log) !== null) {
                        $captured++;
                    }
                }
            });

        return $captured;
    }

    /** @param array<string, mixed> $payload */
    private function batchKey(ActivityLog $activityLog, array $payload): string
    {
        $candidate = $payload['generation_batch_key'] ?? null;

        return is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{8,80}$/', $candidate)
            ? $candidate
            : 'activity-'.$activityLog->id;
    }

    /** @param array<string, mixed> $payload */
    private function revision(?Project $project, ActivityLog $activityLog, array $payload): ?ProjectRevision
    {
        if ($project === null) {
            return null;
        }

        $revisionId = filter_var($payload['revision_id'] ?? null, FILTER_VALIDATE_INT);

        return ProjectRevision::query()->where('project_id', $project->id)
            ->when($revisionId, fn ($query) => $query->whereKey($revisionId), fn ($query) => $query->where('revision_number', $activityLog->revision_number ?? $project->revision))
            ->with('areas.lines')
            ->first();
    }

    /** @param array<string, mixed> $payload
     * @return Collection<int, ProjectLine>
     */
    private function lines(?ProjectRevision $revision, array $payload): Collection
    {
        if ($revision === null) {
            return collect();
        }

        $selectedAreaIds = collect($payload['area_ids'] ?? [])->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id);

        return $revision->areas
            ->when($selectedAreaIds->isNotEmpty(), fn (Collection $areas): Collection => $areas->whereIn('id', $selectedAreaIds))
            ->flatMap->lines
            ->values();
    }

    /** @return array{float|null, float|null} */
    private function values(?Project $project, Collection $lines): array
    {
        if ($project === null || $lines->isEmpty()) {
            return [null, null];
        }

        return [
            round($lines->sum(fn ($line): float => $line->netLineTotalForProject($project)), 2),
            round($lines->sum(fn ($line): float => $line->totalLineTotalForProject($project)), 2),
        ];
    }

    private function owner(?Project $project): ?User
    {
        return $project !== null && filled($project->owner_email)
            ? User::query()->where('email', $project->owner_email)->first()
            : null;
    }

    private function effectiveCover(?float $netValue, ?float $grossValue): ?float
    {
        return $netValue !== null && $grossValue !== null && $grossValue > 0
            ? round((1 - ($netValue / $grossValue)) * 100, 3)
            : null;
    }

    /** @param array<string, mixed> $payload */
    private function documentCount(array $payload): ?int
    {
        $count = filter_var($payload['document_count'] ?? null, FILTER_VALIDATE_INT);

        return $count === false ? null : max(0, $count);
    }

    /** @param array<string, mixed> $payload */
    private function tenderCount(array $payload): int
    {
        $batchSize = filter_var($payload['generation_batch_size'] ?? null, FILTER_VALIDATE_INT);

        if ($batchSize !== false && $batchSize >= 0) {
            return min(100, $batchSize);
        }

        return ! empty($payload['include_cover']) && ! empty($payload['tender_id']) ? 1 : 0;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function metadata(?Project $project, array $payload): array
    {
        return array_filter([
            'filename' => $payload['filename'] ?? null,
            'area_scope' => $payload['area_scope'] ?? null,
            'area_count' => $payload['area_count'] ?? null,
            'tender_id' => $payload['tender_id'] ?? null,
            'tender_account_name' => $payload['tender_account_name'] ?? ($payload['tender'] ?? null),
            'document_pack_id' => $payload['document_pack_id'] ?? null,
            'document_pack_name' => $payload['document_pack_name'] ?? null,
            'contains_quote' => $payload['contains_quote'] ?? null,
            'project_status' => $project?->status?->value,
            'salesforce_value' => $project?->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function isFirstQuoteInBatch(ReportingEvent $event): bool
    {
        return ! ReportingEvent::query()->where('event_type', 'quote')
            ->where('generation_batch_key', $event->generation_batch_key)
            ->whereKeyNot($event->id)
            ->exists();
    }

    private function syncProducts(ReportingEvent $event, Collection $lines): void
    {
        $products = $lines->filter(fn ($line): bool => filled($line->code))
            ->groupBy(fn ($line): string => strtoupper(trim((string) $line->code)))
            ->map(fn (Collection $matchingLines, string $code): array => [
                'product_id' => $matchingLines->first()?->product_id,
                'code' => $code,
                'description' => $matchingLines->first()?->description,
                'quantity' => max(0, (int) $matchingLines->sum('qty')),
            ])->values()->all();

        $event->products()->delete();
        $event->products()->createMany($products);
    }
}
