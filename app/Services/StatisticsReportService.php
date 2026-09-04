<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ReportingEvent;
use App\Models\ReportingEventProduct;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StatisticsReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(
        CarbonImmutable $from,
        CarbonImmutable $until,
        string $groupBy = 'day',
        ?int $userId = null,
        ?string $ownerEmail = null,
        ?string $currency = null,
        bool $includeFinancials = false,
    ): array {
        $events = ReportingEvent::query()
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until)
            ->when($userId, fn (Builder $query) => $query->where('user_id', $userId))
            ->when($ownerEmail, fn (Builder $query) => $query->where('owner_email_snapshot', $ownerEmail))
            ->when($currency, fn (Builder $query) => $query->where('currency', strtoupper($currency)))
            ->orderBy('occurred_at')
            ->get();

        $projects = Project::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->when($userId, fn (Builder $query) => $query->where('user_id', $userId))
            ->when($ownerEmail, fn (Builder $query) => $query->where('owner_email', $ownerEmail))
            ->when($currency, fn (Builder $query) => $query->where('currency', strtoupper($currency)))
            ->with(array_filter([
                'user',
                'revisions',
                'tenders',
                $includeFinancials ? 'activeRevision.areas.lines' : null,
            ]))
            ->get();

        $eventProjects = Project::query()
            ->whereIn('id', $events->pluck('project_id')->filter()->unique())
            ->get();
        $ownerNames = app(ProjectOwnerNameResolver::class)->resolveMany($projects->concat($eventProjects));
        $projectIds = $projects->pluck('id');
        $firstQuoteDates = ReportingEvent::query()->whereIn('project_id', $projectIds)->where('event_type', 'quote')
            ->selectRaw('project_id, MIN(occurred_at) as first_quote_at')->groupBy('project_id')->pluck('first_quote_at', 'project_id');
        $lastActivityDates = ReportingEvent::query()->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, MAX(occurred_at) as last_activity_at')->groupBy('project_id')->pluck('last_activity_at', 'project_id');

        $events->each(function (ReportingEvent $event) use ($ownerNames): void {
            if (blank($event->owner_name_snapshot) && filled($ownerNames->get($event->project_id))) {
                $event->owner_name_snapshot = $ownerNames->get($event->project_id);
            }
        });

        $quotes = $events->where('event_type', 'quote');
        $quoteBatches = $quotes->unique('generation_batch_key')->values();
        $schedules = $events->where('event_type', 'schedule');
        $packs = $events->where('event_type', 'document_pack');
        $logins = $events->where('event_type', 'login');
        $created = $events->where('event_type', 'project_created');

        return [
            'from' => $from,
            'to' => $until->subDay(),
            'summary' => [
                'logins' => $logins->count(),
                'active_users' => $logins->pluck('user_email_snapshot')->filter()->unique()->count(),
                'projects_created' => $projects->count(),
                'schedules' => $schedules->count(),
                'quotes' => $quotes->count(),
                'quote_batches' => $quoteBatches->count(),
                'document_packs' => $packs->count(),
                'median_first_quote_hours' => $this->medianFirstQuoteHours($projects, $firstQuoteDates),
                'quote_regeneration_rate' => $this->regenerationRate($quoteBatches),
                'average_revision_interval_days' => $this->averageRevisionInterval($projects),
            ],
            'financials' => $includeFinancials ? $this->financials($quoteBatches) : [],
            'trend' => $this->trend($events, $from, $until, $groupBy),
            'logins_by_user' => $this->byPerson($logins),
            'projects_by_user' => $this->byPerson($created),
            'projects_by_owner' => $this->byOwner($created),
            'outputs_by_user' => $this->outputsBy($events, 'user_name_snapshot', 'user_email_snapshot'),
            'outputs_by_owner' => $this->outputsBy($events, 'owner_name_snapshot', 'owner_email_snapshot'),
            'schedule_options' => $this->optionCounts($schedules, ['include_datasheets']),
            'quote_options' => $this->optionCounts($quotes, ['include_datasheets', 'include_cover_letter', 'include_legal_page']),
            'quote_tenders' => [
                'average' => round((float) $quoteBatches->avg('tender_count'), 1),
                'total' => (int) $quoteBatches->sum('tender_count'),
            ],
            'status_funnel' => $this->statusFunnel($projects),
            'project_rows' => $this->projectRows($projects, $events, $includeFinancials, $ownerNames),
            'never_quoted' => $this->neverQuoted($projects, $ownerNames),
            'high_value_inactive' => $includeFinancials ? $this->highValueInactive($projects, $lastActivityDates) : collect(),
            'products' => $this->products($quoteBatches),
            'data_since' => ReportingEvent::query()->min('occurred_at'),
        ];
    }

    private function medianFirstQuoteHours(Collection $projects, Collection $firstQuoteDates): ?float
    {
        $values = $projects->map(function (Project $project) use ($firstQuoteDates): ?float {
            $firstQuote = $firstQuoteDates->get($project->id);

            return $firstQuote ? round($project->created_at->diffInMinutes($firstQuote) / 60, 1) : null;
        })->filter()->sort()->values();

        if ($values->isEmpty()) {
            return null;
        }

        $middle = intdiv($values->count(), 2);

        return $values->count() % 2 ? $values[$middle] : round(($values[$middle - 1] + $values[$middle]) / 2, 1);
    }

    private function regenerationRate(Collection $batches): float
    {
        if ($batches->isEmpty()) {
            return 0;
        }

        $regenerated = $batches->groupBy(fn (ReportingEvent $event): string => $event->project_id.'-'.$event->revision_number)
            ->sum(fn (Collection $group): int => max(0, $group->count() - 1));

        return round(($regenerated / $batches->count()) * 100, 1);
    }

    private function averageRevisionInterval(Collection $projects): ?float
    {
        $intervals = $projects->flatMap(fn (Project $project): Collection => $project->revisions->sortBy('created_at')->values()->sliding(2)
            ->map(fn (Collection $pair): float => $pair->first()->created_at->diffInMinutes($pair->last()->created_at) / 1440));

        return $intervals->isEmpty() ? null : round((float) $intervals->average(), 1);
    }

    /** @return array<string, array{net: float, gross: float, cover: float|null, batches: int}> */
    private function financials(Collection $batches): array
    {
        return $batches->groupBy(fn (ReportingEvent $event): string => $event->currency ?: 'GBP')
            ->map(function (Collection $events): array {
                $net = (float) $events->sum('net_value');
                $gross = (float) $events->sum('gross_value');

                return [
                    'net' => round($net, 2),
                    'gross' => round($gross, 2),
                    'cover' => $gross > 0 ? round((1 - ($net / $gross)) * 100, 1) : null,
                    'batches' => $events->count(),
                ];
            })->all();
    }

    private function trend(Collection $events, CarbonImmutable $from, CarbonImmutable $until, string $groupBy): Collection
    {
        $cursor = $from;
        $rows = collect();

        while ($cursor->lt($until)) {
            [$next, $label] = match ($groupBy) {
                'week' => [$cursor->addWeek(), $cursor->format('d M')],
                'month' => [$cursor->addMonth(), $cursor->format('M Y')],
                default => [$cursor->addDay(), $cursor->format('d M')],
            };
            $bucket = $events->filter(fn (ReportingEvent $event): bool => $event->occurred_at->gte($cursor) && $event->occurred_at->lt($next));
            $rows->push([
                'label' => $label,
                'projects' => $bucket->where('event_type', 'project_created')->count(),
                'quotes' => $bucket->where('event_type', 'quote')->count(),
                'schedules' => $bucket->where('event_type', 'schedule')->count(),
                'logins' => $bucket->where('event_type', 'login')->count(),
            ]);
            $cursor = $next;
        }

        return $rows;
    }

    private function byPerson(Collection $events): Collection
    {
        return $events->groupBy('user_email_snapshot')->map(fn (Collection $rows): array => [
            'name' => $rows->first()->user_name_snapshot ?: $rows->first()->user_email_snapshot,
            'email' => $rows->first()->user_email_snapshot,
            'count' => $rows->count(),
        ])->sortByDesc('count')->values();
    }

    private function byOwner(Collection $events): Collection
    {
        return $events->filter(fn (ReportingEvent $event): bool => filled($event->owner_email_snapshot))->groupBy('owner_email_snapshot')->map(fn (Collection $rows): array => [
            'name' => $rows->first()->owner_name_snapshot ?: 'Owner name unavailable',
            'count' => $rows->count(),
        ])->sortByDesc('count')->values();
    }

    private function outputsBy(Collection $events, string $nameField, string $emailField): Collection
    {
        return $events->whereIn('event_type', ['quote', 'schedule', 'document_pack'])->groupBy($emailField)->map(function (Collection $rows) use ($nameField, $emailField): array {
            $first = $rows->first();

            return [
                'name' => $first->{$nameField} ?: ($nameField === 'owner_name_snapshot' ? 'Owner name unavailable' : ($first->{$emailField} ?: 'Unassigned')),
                'quotes' => $rows->where('event_type', 'quote')->count(),
                'schedules' => $rows->where('event_type', 'schedule')->count(),
                'packs' => $rows->where('event_type', 'document_pack')->count(),
                'total' => $rows->count(),
                'values' => $rows->where('event_type', 'quote')->unique('generation_batch_key')
                    ->groupBy(fn (ReportingEvent $event): string => $event->currency ?: 'GBP')
                    ->map(fn (Collection $quotes): array => [
                        'net' => round((float) $quotes->sum('net_value'), 2),
                        'gross' => round((float) $quotes->sum('gross_value'), 2),
                    ])->all(),
            ];
        })->sortByDesc('total')->values();
    }

    /** @param array<int, string> $fields */
    private function optionCounts(Collection $events, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (string $field): array => [$field => [
            'yes' => $events->where($field, true)->count(),
            'no' => $events->where($field, false)->count(),
        ]])->all();
    }

    private function statusFunnel(Collection $projects): Collection
    {
        $order = [
            ProjectStatus::Draft->value,
            ProjectStatus::InProgress->value,
            ProjectStatus::DesignComplete->value,
            ProjectStatus::ApprovalRequested->value,
            ProjectStatus::Approved->value,
            ProjectStatus::Quoted->value,
        ];

        return collect($order)->map(function (string $status, int $index) use ($projects, $order): array {
            $count = $index === 0 ? $projects->count() : $projects->filter(function (Project $project) use ($index, $order): bool {
                $currentIndex = array_search($project->status?->value, $order, true);

                return $currentIndex !== false && $currentIndex >= $index;
            })->count();

            return ['label' => ProjectStatus::from($status)->label(), 'count' => $count];
        });
    }

    private function projectRows(Collection $projects, Collection $events, bool $includeFinancials, Collection $ownerNames): Collection
    {
        return $projects->map(function (Project $project) use ($events, $includeFinancials, $ownerNames): array {
            [$net, $gross] = $includeFinancials ? $this->projectValues($project) : [0.0, 0.0];

            return [
                'reference' => $project->reference_number,
                'name' => $project->name,
                'creator' => $project->user?->name ?? $project->created_by_email,
                'owner' => $ownerNames->get($project->id) ?: 'Owner name unavailable',
                'status' => $project->status?->label() ?? 'Unknown',
                'revisions' => $project->revisions->count(),
                'tenders' => $project->tenders->count(),
                'currency' => strtoupper((string) ($project->currency ?: 'GBP')),
                'net' => $includeFinancials ? $net : null,
                'gross' => $includeFinancials ? $gross : null,
                'cover' => $includeFinancials && $gross > 0 ? round((1 - ($net / $gross)) * 100, 1) : null,
                'has_cover' => $project->has_cover,
                'quotes' => $events->where('project_id', $project->id)->where('event_type', 'quote')->count(),
                'schedules' => $events->where('project_id', $project->id)->where('event_type', 'schedule')->count(),
            ];
        });
    }

    private function neverQuoted(Collection $projects, Collection $ownerNames): Collection
    {
        $quotedProjectIds = ReportingEvent::query()->where('event_type', 'quote')->whereIn('project_id', $projects->pluck('id'))->pluck('project_id')->unique();

        return $projects->whereNotIn('id', $quotedProjectIds)->map(fn (Project $project): array => [
            'reference' => $project->reference_number,
            'name' => $project->name,
            'owner' => $ownerNames->get($project->id) ?: 'Owner name unavailable',
            'created' => $project->created_at,
        ])->values();
    }

    private function highValueInactive(Collection $projects, Collection $lastActivityDates): Collection
    {
        $threshold = (float) config('statistics.high_value_threshold', 25000);
        $cutoff = now()->subDays((int) config('statistics.inactive_days', 30));

        return $projects->map(function (Project $project) use ($lastActivityDates): ?array {
            [$net, $gross] = $this->projectValues($project);
            $lastActivity = $lastActivityDates->get($project->id) ?? $project->updated_at;

            return ['project' => $project, 'net' => $net, 'gross' => $gross, 'last_activity' => $lastActivity];
        })->filter(fn (array $row): bool => $row['gross'] >= $threshold && CarbonImmutable::parse($row['last_activity'])->lt($cutoff))->values();
    }

    /** @return array{float, float} */
    private function projectValues(Project $project): array
    {
        $lines = $project->activeRevision?->areas?->flatMap->lines ?? collect();

        return [
            round($lines->sum(fn ($line): float => $line->netLineTotalForProject($project)), 2),
            round($lines->sum(fn ($line): float => $line->totalLineTotalForProject($project)), 2),
        ];
    }

    private function products(Collection $quoteBatches): Collection
    {
        return ReportingEventProduct::query()->whereIn('reporting_event_id', $quoteBatches->pluck('id'))
            ->selectRaw('code, MAX(description) as description, SUM(quantity) as quantity, COUNT(DISTINCT reporting_event_id) as quote_count')
            ->groupBy('code')->orderByDesc('quote_count')->orderByDesc('quantity')->limit(25)->get();
    }
}
