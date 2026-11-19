<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Projects\Pages\ProjectHistory;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActivityLogFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 12:00:00');
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_user_reference_action_dates_and_search_filters_combine_across_the_table(): void
    {
        $actor = User::factory()->create(['name' => 'Same name']);
        $otherActor = User::factory()->create(['name' => 'Same name']);
        $project = Project::factory()->create(['reference_number' => 'REF-001']);
        $otherProject = Project::factory()->create(['reference_number' => 'REF-002']);
        $included = $this->log($actor, '2026-11-17 23:59:59', $project);
        $otherUser = $this->log($otherActor, '2026-11-17 10:00:00', $project);
        $otherReference = $this->log($actor, '2026-11-17 10:00:00', $otherProject);
        $old = $this->log($actor, '2026-11-15 23:59:59', $project);
        $late = $this->log($actor, '2026-11-18 00:00:00', $project);
        $otherAction = $this->log($actor, '2026-11-17 12:00:00', $project, 'calendar.created');

        $component = Livewire::test(ListActivityLogs::class)
            ->filterTable('user_id', [$actor->id])
            ->filterTable('project_id', [$project->id])
            ->filterTable('action_type', 'user.login')
            ->filterTable('date_range', ['from' => '2026-11-16', 'until' => '2026-11-17'])
            ->searchTable('filter-search-marker')
            ->assertCanSeeTableRecords([$included])
            ->assertCanNotSeeTableRecords([$otherUser, $otherReference, $old, $late, $otherAction]);
        $this->assertSame(1, $component->instance()->getTableRecords()->total());
        Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords([$included])
            ->assertCanNotSeeTableRecords([$otherUser, $otherReference, $old, $late, $otherAction]);
        $component->filterTable('date_range', [])->assertCanSeeTableRecords([$included, $old, $late]);
    }

    public function test_date_range_includes_both_complete_boundary_days_and_applies_before_pagination(): void
    {
        $actor = User::factory()->create();
        $first = $this->log($actor, '2026-11-17 00:00:00');
        $last = $this->log($actor, '2026-11-17 23:59:59');
        $before = $this->log($actor, '2026-11-16 23:59:59');
        $after = $this->log($actor, '2026-11-18 00:00:00');
        for ($index = 0; $index < 18; $index++) {
            $this->log($actor, '2026-11-17 12:00:00');
        }
        $component = Livewire::test(ListActivityLogs::class)
            ->filterTable('date_range', ['from' => '2026-11-17 14:00:00', 'until' => '2026-11-17 01:00:00'])
            ->assertCanSeeTableRecords([$last])
            ->assertCanNotSeeTableRecords([$before, $after]);
        $this->assertSame(20, $component->instance()->getTableRecords()->total());
        $component->set('paginators.page', 2)->assertCanSeeTableRecords([$first]);
    }

    public function test_date_range_can_be_open_ended_and_does_not_bypass_retention(): void
    {
        $actor = User::factory()->create();
        $old = $this->log($actor, '2026-07-01 10:00:00');
        $first = $this->log($actor, '2026-11-16 10:00:00');
        $last = $this->log($actor, '2026-11-18 10:00:00');
        $component = Livewire::test(ListActivityLogs::class)
            ->filterTable('date_range', ['from' => '2026-11-17'])
            ->assertCanSeeTableRecords([$last])->assertCanNotSeeTableRecords([$old, $first]);
        $component->filterTable('date_range', ['until' => '2026-11-17'])
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$old, $last]);
        $component->filterTable('date_range', ['from' => '2026-01-01', 'until' => '2026-11-18'])
            ->assertCanSeeTableRecords([$first, $last])->assertCanNotSeeTableRecords([$old]);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalidRanges(): array
    {
        return [
            'reversed' => [['from' => '2026-11-18', 'until' => '2026-11-17']],
            'malformed' => [['from' => 'not-a-date']],
            'impossible date' => [['until' => '2026-11-31']],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_date_ranges_return_no_records(array $range): void
    {
        $entry = $this->log(User::factory()->create(), '2026-11-17 10:00:00');
        $component = Livewire::test(ListActivityLogs::class);
        $query = $component->instance()->getTable()->getFilter('date_range')
            ->apply(ActivityLog::query()->withinRetention(), $range);
        $this->assertFalse($query->whereKey($entry->id)->exists());
    }

    public function test_reference_filter_search_uses_project_reference_numbers(): void
    {
        $project = Project::factory()->create(['reference_number' => 'SEARCH-123']);
        Project::factory()->create(['reference_number' => 'OTHER-456']);
        $component = Livewire::test(ListActivityLogs::class);
        $field = collect($component->instance()->getTable()->getFilter('project_id')->getSchema()->getFlatFields())->first();
        $options = $field->getSearchResults('SEARCH-123');
        $this->assertSame([$project->id => 'SEARCH-123'], $options);
    }

    public function test_new_filters_do_not_change_project_history_scope_or_access_permissions(): void
    {
        $actor = User::factory()->create();
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $included = $this->log($actor, '2026-11-17 10:00:00', $project);
        $excluded = $this->log($actor, '2026-11-17 10:00:00', $other);
        Livewire::test(ProjectHistory::class, ['record' => $project->id])
            ->assertCanSeeTableRecords([$included])->assertCanNotSeeTableRecords([$excluded]);
        $this->actingAs($actor);
        Livewire::test(ListActivityLogs::class)->assertForbidden();
    }

    private function log(User $actor, string $date, ?Project $project = null, string $action = 'user.login'): ActivityLog
    {
        $log = ActivityLog::create([
            'user_id' => $actor->id, 'project_id' => $project?->id,
            'user_email_snapshot' => $actor->email, 'project_name_snapshot' => $project?->name,
            'action_type' => $action, 'payload' => ['login_context' => ['display' => 'filter-search-marker']],
        ]);
        $log->forceFill(['created_at' => $date])->save();

        return $log;
    }
}
