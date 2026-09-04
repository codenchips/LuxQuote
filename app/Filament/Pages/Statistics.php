<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Models\ReportingEvent;
use App\Models\User;
use App\Services\StatisticsReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

class Statistics extends Page
{
    protected static ?string $navigationLabel = 'Statistics';

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.statistics';

    #[Url]
    public string $section = 'overview';

    public string $from = '';

    public string $to = '';

    public string $groupBy = 'day';

    public ?string $activePreset = 'month';

    public ?int $userId = null;

    public ?string $ownerEmail = null;

    public ?string $currency = null;

    /** @var array<string, mixed> */
    public array $report = [];

    public ?string $reportError = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->refreshReport();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('statistics.view') ?? false;
    }

    public function refreshReport(): void
    {
        $validated = $this->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'groupBy' => ['required', 'in:day,week,month'],
            'userId' => ['nullable', 'integer', 'exists:users,id'],
            'ownerEmail' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $until = CarbonImmutable::parse($validated['to'])->addDay()->startOfDay();
        $rangeDays = (int) $from->diffInDays($until);
        $maximumDays = min(
            (int) config('statistics.max_range_days', 3650),
            match ($validated['groupBy']) {
                'day' => 400,
                'week' => 3650,
                default => (int) config('statistics.max_range_days', 3650),
            },
        );

        if ($rangeDays > $maximumDays) {
            $this->addError('to', "That range is too large for {$validated['groupBy']} grouping. Choose a broader grouping or a shorter period.");

            return;
        }

        if (! Schema::hasTable('reporting_events') || ! Schema::hasTable('reporting_event_products')) {
            $this->report = [];
            $this->reportError = 'Statistics are being prepared. Please ask an administrator to run the pending database migrations.';

            return;
        }

        try {
            $this->report = app(StatisticsReportService::class)->report(
                $from->utc(),
                $until->utc(),
                $validated['groupBy'],
                $validated['userId'],
                $validated['ownerEmail'],
                $validated['currency'],
                auth()->user()?->can('pricing.view') ?? false,
            );
            $this->reportError = null;
        } catch (Throwable $exception) {
            $this->report = [];
            $this->reportError = 'Statistics could not be loaded. Please try again.';
            Log::error('Statistics report generation failed.', ['exception' => $exception->getMessage()]);
        }
    }

    public function setPreset(string $preset): void
    {
        abort_unless(in_array($preset, ['today', 'week', 'month', 'quarter'], true), 404);

        $today = now();
        [$from, $groupBy] = match ($preset) {
            'today' => [$today->copy(), 'day'],
            'week' => [$today->copy()->startOfWeek(), 'day'],
            'quarter' => [$today->copy()->firstOfQuarter(), 'week'],
            default => [$today->copy()->startOfMonth(), 'day'],
        };
        $this->from = $from->toDateString();
        $this->to = $today->toDateString();
        $this->groupBy = $groupBy;
        $this->activePreset = $preset;
        $this->refreshReport();
    }

    public function updatedFrom(): void
    {
        $this->activePreset = null;
    }

    public function updatedTo(): void
    {
        $this->activePreset = null;
    }

    /** @return array<int, string> */
    public function getUserOptionsProperty(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public function getOwnerOptionsProperty(): array
    {
        if (! Schema::hasTable('reporting_events')) {
            return [];
        }

        return Project::query()->whereNotNull('owner_email')->orderBy('owner_name')->get()
            ->mapWithKeys(fn (Project $project): array => [$project->owner_email => $project->owner_name ?: 'Owner name unavailable'])
            ->sort()->all();
    }

    /** @return array<string, string> */
    public function getCurrencyOptionsProperty(): array
    {
        if (! Schema::hasTable('reporting_events')) {
            return [];
        }

        return ReportingEvent::query()->whereNotNull('currency')->distinct()->orderBy('currency')->pluck('currency', 'currency')->all();
    }
}
